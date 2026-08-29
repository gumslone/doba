<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Jobs\DeliverWebhook;
use App\Models\ApiClient;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Settling the rest of a stay online (§8).
 *
 * The deposit's counterpart, with different failure economics: the
 * booking is already confirmed, so no inventory hangs on this payment —
 * but the money rules are the same ones, enforced in the same service.
 */
function confirmedBooking(): Booking
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

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => 'guest@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2
    );

    return app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test')->fresh();
}

beforeEach(function (): void {
    config()->set('services.stripe.secret', 'sk_test');
    config()->set('services.stripe.webhook_secret', 'whsec_test');
    config()->set('doba.payment.gateway', 'stripe');
    config()->set('doba.locales', ['en']);

    Http::fake([
        'api.stripe.com/*' => Http::response(['id' => 'pi_bal_1', 'client_secret' => 'pi_bal_1_secret']),
    ]);
});

it('collects the balance through the same service, under its own rules', function (): void {
    $booking = confirmedBooking();

    expect($booking->balance_due)->toBe(20000);

    $payment = app(PaymentService::class)->initiateBalance(GatewayRegistry::make('stripe'), $booking);

    expect($payment->type)->toBe('balance')
        ->and($payment->amount)->toBe(20000)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        // The amount is INSIDE the key: retrying the same balance cannot
        // double-charge, a changed balance cannot charge the old number.
        ->and($payment->idempotency_key)->toBe("doba-{$booking->reference}-balance-20000");
});

it('refuses a balance it should not collect', function (): void {
    $pending = tap(confirmedBooking())->forceFill(['status' => BookingStatus::Pending])->save();

    expect(fn () => app(PaymentService::class)->initiateBalance(GatewayRegistry::make('stripe'), Booking::sole()->forceFill(['status' => BookingStatus::Pending])))
        ->toThrow(InvalidArgumentException::class);

    $settled = Booking::sole()->forceFill(['status' => BookingStatus::Confirmed, 'balance_due' => 0]);

    expect(fn () => app(PaymentService::class)->initiateBalance(GatewayRegistry::make('stripe'), $settled))
        ->toThrow(InvalidArgumentException::class, 'nothing left');

    // Manual means the desk settles it: a pending row nothing will ever
    // confirm is not a payment, it is a loose end.
    $manual = Booking::sole()->forceFill(['status' => BookingStatus::Confirmed, 'balance_due' => 20000]);

    expect(fn () => app(PaymentService::class)->initiateBalance(GatewayRegistry::make('manual'), $manual))
        ->toThrow(InvalidArgumentException::class, 'desk');
});

it('lets the guest pay from the manage page, and credits it off the webhook', function (): void {
    Queue::fake();

    $booking = confirmedBooking();
    $manage = "/en/booking/manage/{$booking->reference}/{$booking->manage_token}";

    // The button is there, next to the number it makes zero.
    $this->get($manage)->assertOk()->assertSee('Pay it now');

    // POST creates the intent; the GET page only renders it.
    $this->post("{$manage}/balance")->assertRedirect("{$manage}/balance");
    $this->get("{$manage}/balance")->assertOk()->assertSee('pi_bal_1_secret');

    expect(Payment::query()->where('type', 'balance')->count())->toBe(1);

    // A second click does not mint a second intent for the same money.
    $this->post("{$manage}/balance");
    expect(Payment::query()->where('type', 'balance')->count())->toBe(1);

    // The WEBHOOK credits it — the redirect never does (§8).
    $payload = (string) json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_bal_1', 'amount_received' => 20000, 'currency' => 'eur']],
    ]);
    $timestamp = now()->timestamp;

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    $booking->refresh();

    expect($booking->paid_amount)->toBe(20000)
        ->and($booking->balance_due)->toBe(0)
        // Confirmed stays confirmed — a balance credit is money, not a
        // state transition.
        ->and($booking->status)->toBe(BookingStatus::Confirmed);

    // And the page now says there is nothing to pay, instead of offering
    // to collect the same money twice.
    $this->get("{$manage}/balance")->assertRedirect($manage);
});

it('tells subscribed partners that money moved', function (): void {
    Queue::fake();

    ['client' => $client] = ApiClient::issue('CM', ApiClient::SCOPES);
    $client->webhookEndpoints()->create([
        'url' => 'https://partner.example/hooks',
        'events' => ['payment.succeeded'],
        'secret' => str_repeat('s', 48),
    ]);

    $booking = confirmedBooking();
    app(PaymentService::class)->initiateBalance(GatewayRegistry::make('stripe'), $booking);

    $payload = (string) json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_bal_1', 'amount_received' => 20000, 'currency' => 'eur']],
    ]);
    $timestamp = now()->timestamp;

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    // payment.succeeded had been declared in WebhookEndpoint::EVENTS
    // since the webhooks slice and emitted by nothing — a partner could
    // subscribe to silence. This pins the emission.
    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use ($booking): bool {
        return $job->event === 'payment.succeeded'
            && $job->payload['reference'] === $booking->reference
            && $job->payload['type'] === 'balance'
            && $job->payload['balance_due']['amount'] === 0;
    });
});

it('keeps the desk-settled path quiet: manual gateway shows no pay button', function (): void {
    config()->set('doba.payment.gateway', 'manual');

    $booking = confirmedBooking();
    $manage = "/en/booking/manage/{$booking->reference}/{$booking->manage_token}";

    $this->get($manage)->assertOk()
        ->assertSee(html_entity_decode('&euro;'), false)
        ->assertDontSee('Pay it now')
        ->assertSee('front desk');

    // And the POST route refuses politely rather than minting an intent.
    $this->post("{$manage}/balance")->assertRedirect($manage);
    expect(Payment::query()->where('type', 'balance')->count())->toBe(0);
});
