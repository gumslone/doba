<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function paidBooking(): Booking
{
    $roomType = RoomType::create([
        'code' => 'DBL-'.uniqid(),
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'default_rate' => 10000,
        'total_units' => 1,
    ]);

    $checkIn = CarbonImmutable::today()->addDays(14);

    foreach (range(0, 2) as $i) {
        Availability::create([
            'room_type_id' => $roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 1,
        ]);
    }

    return app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => 'guest@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2
    );
}

function stripeSignature(string $payload, ?int $timestamp = null): string
{
    $timestamp ??= now()->timestamp;

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');
}

function stripeSucceededPayload(string $intentId, int $amount = 20000): string
{
    return (string) json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => $intentId, 'amount_received' => $amount, 'currency' => 'eur']],
    ]);
}

beforeEach(function (): void {
    config()->set('services.stripe.secret', 'sk_test');
    config()->set('services.stripe.webhook_secret', 'whsec_test');
    config()->set('services.liqpay.public_key', 'pub_test');
    config()->set('services.liqpay.private_key', 'priv_test');
    config()->set('services.coinbase.webhook_secret', 'cb_secret');
    config()->set('services.paypal.client_id', 'pp_id');
    config()->set('services.paypal.secret', 'pp_secret');
    config()->set('services.paypal.webhook_id', 'wh_id');
});

it('initiates a Stripe deposit and stores the pending payment', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response(['id' => 'pi_123', 'client_secret' => 'pi_123_secret']),
    ]);

    $booking = paidBooking();

    $payment = app(PaymentService::class)->initiate(GatewayRegistry::make('stripe'), $booking);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gateway_payment_id)->toBe('pi_123')
        ->and($payment->amount)->toBe(20000);

    // The §8 idempotency: the intent request carries a booking-derived key.
    Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', "doba-{$booking->reference}-deposit")
        && (string) $request['amount'] === '20000');

    // Re-initiating reuses the same row, never a second charge.
    app(PaymentService::class)->initiate(GatewayRegistry::make('stripe'), $booking);
    expect(Payment::count())->toBe(1);
});

it('confirms the booking from the Stripe webhook — never from the browser', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'client_secret' => 's'])]);

    $booking = paidBooking();
    app(PaymentService::class)->initiate(GatewayRegistry::make('stripe'), $booking);

    $payload = stripeSucceededPayload('pi_123');

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($booking->fresh())
        ->status->toBe(BookingStatus::Confirmed)
        ->paid_amount->toBe(20000)
        ->balance_due->toBe(0);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Paid);
});

it('treats a redelivered success webhook as a no-op', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'client_secret' => 's'])]);

    $booking = paidBooking();
    app(PaymentService::class)->initiate(GatewayRegistry::make('stripe'), $booking);

    $payload = stripeSucceededPayload('pi_123');
    $headers = fn (): array => [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', '/webhooks/stripe', [], [], [], $headers(), $payload)->assertOk();
    $this->call('POST', '/webhooks/stripe', [], [], [], $headers(), $payload)->assertOk();

    // Paid once, confirmed once — Stripe retries are free (§8).
    expect($booking->fresh()->paid_amount)->toBe(20000)
        ->and($booking->statusHistory()->where('to_status', BookingStatus::Confirmed)->count())->toBe(1);
});

it('rejects a bad signature and a stale timestamp without side effects', function (): void {
    $payload = stripeSucceededPayload('pi_123');

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't='.now()->timestamp.',v1=forged',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, now()->subMinutes(10)->timestamp),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);

    expect(Payment::count())->toBe(0);
});

it('marks a failed payment but keeps the booking pending for a retry', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'client_secret' => 's'])]);

    $booking = paidBooking();
    app(PaymentService::class)->initiate(GatewayRegistry::make('stripe'), $booking);

    $payload = (string) json_encode([
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => ['id' => 'pi_123']],
    ]);

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('auto-refunds a payment that lands after the room was resold', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'client_secret' => 's'])]);

    $booking = paidBooking();
    app(PaymentService::class)->initiate(GatewayRegistry::make('stripe'), $booking);

    // Hold expires, release frees the unit, someone else books it.
    $this->travel(30)->minutes();
    $booking->holds()->update(['released_at' => now()]);
    Availability::query()->where('held', '>', 0)->update(['held' => 0, 'booked' => 1]);

    $payload = stripeSucceededPayload('pi_123');

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    // Refunded, cancelled, and above all: never confirmed (§6/§8).
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/refunds')
        && $request['payment_intent'] === 'pi_123');

    expect($booking->fresh())
        ->status->toBe(BookingStatus::Cancelled)
        ->paid_amount->toBe(0);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Refunded);
});

it('refuses a forged webhook when the signing secret is unset (fail closed)', function (): void {
    config()->set('services.stripe.webhook_secret', ''); // operator forgot it
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'client_secret' => 's'])]);

    $booking = paidBooking();
    app(PaymentService::class)->initiate(GatewayRegistry::make('stripe'), $booking);

    $payload = stripeSucceededPayload('pi_123');
    // Signature the attacker computes with the empty key.
    $forged = 't='.now()->timestamp.',v1='.hash_hmac('sha256', now()->timestamp.'.'.$payload, '');

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $forged,
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);

    // The empty-key HMAC did not become a valid confirmation.
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('refuses a LiqPay callback when the private key is unset', function (): void {
    config()->set('services.liqpay.private_key', '');
    $booking = paidBooking();
    app(PaymentService::class)->initiate(GatewayRegistry::make('liqpay'), $booking);

    $data = base64_encode((string) json_encode([
        'status' => 'success',
        'order_id' => "doba-{$booking->reference}-deposit",
        'amount' => '200.00',
        'currency' => 'EUR',
    ]));

    $this->post('/webhooks/liqpay', [
        'data' => $data,
        'signature' => base64_encode(sha1(''.$data.'', true)),
    ])->assertStatus(400);

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('does not confirm on an underpaying webhook', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'client_secret' => 's'])]);

    $booking = paidBooking();
    app(PaymentService::class)->initiate(GatewayRegistry::make('stripe'), $booking);

    // Signed event, but for less than the expected deposit.
    $payload = stripeSucceededPayload('pi_123', amount: 5000);

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($booking->fresh())
        ->status->toBe(BookingStatus::Pending)
        ->paid_amount->toBe(0);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed);
});

it('confirms immediately on the manual gateway', function (): void {
    $booking = paidBooking();

    app(PaymentService::class)->initiate(GatewayRegistry::make('manual'), $booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(Payment::sole())
        ->status->toBe(PaymentStatus::Pending)   // awaiting the transfer
        ->gateway->toBe('manual');
});

it('confirms from a verified LiqPay callback', function (): void {
    $booking = paidBooking();
    $payment = app(PaymentService::class)->initiate(GatewayRegistry::make('liqpay'), $booking);

    expect($payment->gateway_payment_id)->toBe("doba-{$booking->reference}-deposit");

    $data = base64_encode((string) json_encode([
        'status' => 'success',
        'order_id' => $payment->gateway_payment_id,
        'amount' => '200.00',
        'currency' => 'EUR',
    ]));
    $signature = base64_encode(sha1('priv_test'.$data.'priv_test', true));

    $this->post('/webhooks/liqpay', ['data' => $data, 'signature' => $signature])->assertOk();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    // Tampered signature is refused.
    $this->post('/webhooks/liqpay', ['data' => $data, 'signature' => 'forged'])->assertStatus(400);
});

it('confirms from a verified Coinbase Commerce webhook', function (): void {
    Http::fake([
        'api.commerce.coinbase.com/*' => Http::response(['data' => ['id' => 'charge_1', 'hosted_url' => 'https://commerce.coinbase.com/charges/x']]),
    ]);

    $booking = paidBooking();
    app(PaymentService::class)->initiate(GatewayRegistry::make('coinbase'), $booking);

    $payload = (string) json_encode([
        'event' => [
            'type' => 'charge:confirmed',
            'data' => ['id' => 'charge_1', 'pricing' => ['local' => ['amount' => '200.00', 'currency' => 'EUR']]],
        ],
    ]);

    $this->call('POST', '/webhooks/coinbase', [], [], [], [
        'HTTP_X_CC_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $payload, 'cb_secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('falls back to a manual refund flag when crypto lands after a resell', function (): void {
    Http::fake([
        'api.commerce.coinbase.com/*' => Http::response(['data' => ['id' => 'charge_1', 'hosted_url' => 'https://x']]),
    ]);

    $booking = paidBooking();
    $payment = app(PaymentService::class)->initiate(GatewayRegistry::make('coinbase'), $booking);

    $this->travel(30)->minutes();
    $booking->holds()->update(['released_at' => now()]);
    Availability::query()->where('held', '>', 0)->update(['held' => 0, 'booked' => 1]);

    $payload = (string) json_encode([
        'event' => [
            'type' => 'charge:confirmed',
            'data' => ['id' => 'charge_1', 'pricing' => ['local' => ['amount' => '200.00', 'currency' => 'EUR']]],
        ],
    ]);

    $this->call('POST', '/webhooks/coinbase', [], [], [], [
        'HTTP_X_CC_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $payload, 'cb_secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    // Never confirmed — but crypto cannot auto-refund, so the money stays
    // visibly 'paid' as an open liability for staff to settle.
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('confirms from a PayPal capture verified against the PayPal API', function (): void {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
        '*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER1',
            'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
        ]),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    $booking = paidBooking();
    $payment = app(PaymentService::class)->initiate(GatewayRegistry::make('paypal'), $booking);

    expect($payment->gateway_payment_id)->toBe('ORDER1');

    $this->postJson('/webhooks/paypal', [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE1',
            'amount' => ['value' => '200.00', 'currency_code' => 'EUR'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1']],
        ],
    ])->assertOk();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('rejects a PayPal webhook that PayPal will not vouch for', function (): void {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
    ]);

    $this->postJson('/webhooks/paypal', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => []])
        ->assertStatus(400);
});

it('refunds partially and fully with correct bookkeeping', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'client_secret' => 's'])]);

    $booking = paidBooking();
    $service = app(PaymentService::class);
    $payment = $service->initiate(GatewayRegistry::make('stripe'), $booking);

    $payload = stripeSucceededPayload('pi_123');
    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    $service->refund(GatewayRegistry::make('stripe'), $payment->fresh(), 5000);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($booking->fresh()->paid_amount)->toBe(15000);

    // The remainder; over-refunding is refused.
    $service->refund(GatewayRegistry::make('stripe'), $payment->fresh());

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($booking->fresh()->paid_amount)->toBe(0);

    expect(fn () => $service->refund(GatewayRegistry::make('stripe'), $payment->fresh(), 100))
        ->toThrow(InvalidArgumentException::class);
});
