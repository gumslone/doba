<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Invoicing\InvoiceBuilder;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Domain\Vouchers\VoucherException;
use App\Domain\Vouchers\VoucherRenderer;
use App\Domain\Vouchers\VoucherService;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Mail\VoucherIssued;
use App\Mail\VoucherOrdered;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\GiftVoucher;
use App\Models\Payment;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Gift vouchers (§8): money received in advance. A means of payment —
 * never a discount — with a balance that must never be spent twice.
 */
function voucherStay(bool $confirmed = true, string $email = 'guest@example.com'): Booking
{
    $roomType = RoomType::query()->where('code', 'GV')->firstOr(function (): RoomType {
        $type = RoomType::create(['code' => 'GV', 'base_occupancy' => 2, 'max_occupancy' => 2, 'default_rate' => 10000, 'total_units' => 12]);
        $type->translations()->create(['locale' => 'en', 'slug' => 'gv', 'name' => 'Double']);

        foreach (range(0, 20) as $i) {
            Availability::create(['room_type_id' => $type->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString(), 'allotment' => 12]);
        }

        return $type;
    });

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(7);

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => $email, 'first_name' => 'Gift', 'last_name' => 'Guest'], adults: 2,
    );

    return $confirmed ? app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test')->fresh() : $booking->fresh();
}

function activeVoucher(int $amount = 10000): GiftVoucher
{
    return app(VoucherService::class)->issue(['amount' => $amount, 'buyer_name' => 'Tante Erna', 'buyer_email' => 'erna@example.com', 'recipient_name' => 'Lena', 'locale' => 'en'], mail: false);
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    config()->set('doba.features.vouchers', true);
    Mail::fake();
    app(MailSettings::class)->confirm();
    Setting::put('contact', 'email', 'desk@alpenhof.example');
    Setting::put('vouchers', 'payment_instructions', "IBAN DE00 1234\nBIC ALPENHOF");
    HotelSettings::flush();
    app(HotelSettings::class)->refresh();
    RateLimiter::clear('contact:127.0.0.1');
});

it('does not exist on an install that does not sell vouchers', function (): void {
    config()->set('doba.features.vouchers', false);

    $this->get('/en/gift-vouchers')->assertNotFound();
    $this->post('/en/gift-vouchers', ['amount' => 10000, 'buyer_name' => 'A', 'buyer_email' => 'a@example.com'])->assertNotFound();
    $this->get('/en')->assertDontSee('/en/gift-vouchers', false);
});

it('lives on its translated path and in the navigation when it does', function (): void {
    $this->get('/en/gift-vouchers')->assertOk()->assertSee('Gift vouchers');
    $this->get('/de/gutscheine')->assertOk()->assertSee('Gutschein bestellen');
    $this->get('/en')->assertSee('/en/gift-vouchers', false);
});

it('takes an order that is worth nothing until it is paid', function (): void {
    $this->post('/en/gift-vouchers', [
        'amount' => 15000, 'recipient_name' => 'Lena', 'message' => 'Happy birthday!',
        'buyer_name' => 'Tante Erna', 'buyer_email' => 'erna@example.com', 'website' => '',
    ])->assertRedirect('/en/gift-vouchers')->assertSessionHas('voucher_ordered', 'erna@example.com');

    $voucher = GiftVoucher::sole();

    expect($voucher->status)->toBe(GiftVoucher::PENDING)
        ->and($voucher->initial_amount)->toBe(15000)
        ->and($voucher->balance)->toBe(15000)
        ->and($voucher->code)->toMatch('/^GV-[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and($voucher->expires_on)->toBeNull();

    // The buyer learns how to pay, with the code to quote…
    Mail::assertQueued(VoucherOrdered::class, function (VoucherOrdered $mail) use ($voucher): bool {
        $html = $mail->render();

        return $mail->hasTo('erna@example.com') && str_contains($html, 'IBAN DE00 1234') && str_contains($html, $voucher->code);
    });
    // …but does not get the voucher.
    Mail::assertNotQueued(VoucherIssued::class);

    // And it cannot be spent.
    expect(fn () => app(VoucherService::class)->redeem($voucher->code, voucherStay()))
        ->toThrow(VoucherException::class, 'vouchers.error_unpaid');
});

it('takes a typed amount over a preset, and refuses one outside the limits', function (): void {
    $this->post('/en/gift-vouchers', ['amount' => 5000, 'amount_other' => '80', 'buyer_name' => 'A', 'buyer_email' => 'a@example.com']);
    expect(GiftVoucher::sole()->initial_amount)->toBe(8000);

    $this->from('/en/gift-vouchers')->post('/en/gift-vouchers', ['amount_other' => '5', 'buyer_name' => 'A', 'buyer_email' => 'a@example.com'])
        ->assertSessionHasErrors('amount');
    // A bot that fills the invisible field gets nowhere.
    $this->post('/en/gift-vouchers', ['amount' => 5000, 'buyer_name' => 'A', 'buyer_email' => 'a@example.com', 'website' => 'http://spam.example'])
        ->assertSessionHasErrors('website');

    expect(GiftVoucher::query()->count())->toBe(1);
});

it('becomes valid when the hotel has the money, and goes to the buyer as a PDF', function (): void {
    $voucher = app(VoucherService::class)->order(['amount' => 10000, 'buyer_name' => 'Tante Erna', 'buyer_email' => 'erna@example.com', 'locale' => 'de']);

    $this->actingAs(User::factory()->create())->post('/admin/vouchers/'.$voucher->id.'/activate')->assertSessionHas('saved');

    $voucher->refresh();

    expect($voucher->status)->toBe(GiftVoucher::ACTIVE)
        ->and($voucher->activated_at)->not->toBeNull()
        // To the end of the year, three years on.
        ->and($voucher->expires_on->toDateString())->toBe(CarbonImmutable::today(config('doba.timezone'))->addYears(3)->endOfYear()->toDateString());

    Mail::assertQueued(VoucherIssued::class, function (VoucherIssued $mail): bool {
        return $mail->hasTo('erna@example.com')
            && str_contains($mail->envelope()->subject, 'Gutschein')
            && count($mail->attachments()) === 1;
    });

    expect(app(VoucherRenderer::class)->render($voucher))->toStartWith('%PDF');

    // Twice is an error, not a second mail.
    $this->actingAs(User::factory()->create())->post('/admin/vouchers/'.$voucher->id.'/activate')->assertSessionHasErrors('voucher');
});

it('pays a booking as a payment, never as a discount', function (): void {
    $booking = voucherStay();
    $voucher = activeVoucher(5000);
    $totalBefore = $booking->total;

    $redemption = app(VoucherService::class)->redeem($voucher->code, $booking);
    $booking->refresh();

    expect($redemption->amount)->toBe(5000)
        ->and($booking->total)->toBe($totalBefore)                 // the price did not move
        ->and($booking->discount_total)->toBe(0)
        ->and($booking->paid_amount)->toBe(5000)
        ->and($booking->balance_due)->toBe($totalBefore - 5000)
        ->and($voucher->fresh()->balance)->toBe(0)
        ->and($voucher->fresh()->status)->toBe(GiftVoucher::REDEEMED);

    $payment = Payment::query()->where('gateway', 'voucher')->sole();

    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->amount)->toBe(5000)
        ->and($payment->payload['voucher'])->toBe($voucher->code);

    // The invoice is for the stay, in full: a voucher is how it was paid.
    $invoice = app(InvoiceBuilder::class)->issue($booking);
    expect($invoice->gross_total)->toBe($totalBefore);
});

it('takes only what is owed and keeps the rest for next time', function (): void {
    $booking = voucherStay();          // owes 200.00
    $voucher = activeVoucher(30000);   // worth 300.00

    app(VoucherService::class)->redeem($voucher->code, $booking);

    expect($booking->fresh()->balance_due)->toBe(0)
        ->and($voucher->fresh()->balance)->toBe(10000)
        ->and($voucher->fresh()->status)->toBe(GiftVoucher::ACTIVE);

    // Nothing left to pay on that booking.
    expect(fn () => app(VoucherService::class)->redeem($voucher->code, $booking->fresh()))
        ->toThrow(VoucherException::class, 'vouchers.error_nothing_due');
});

it('is never spent twice', function (): void {
    $voucher = activeVoucher(25000);
    $first = voucherStay(email: 'one@example.com');     // owes 200.00
    $second = voucherStay(email: 'two@example.com');    // owes 200.00
    $third = voucherStay(email: 'three@example.com');

    app(VoucherService::class)->redeem($voucher->code, $first);
    $partial = app(VoucherService::class)->redeem($voucher->code, $second);

    // The second booking got what the first one left, not the face value.
    expect($partial->amount)->toBe(5000)
        ->and($second->fresh()->balance_due)->toBe(15000)
        ->and($voucher->fresh()->balance)->toBe(0)
        ->and((int) Payment::query()->where('gateway', 'voucher')->sum('amount'))->toBe(25000);

    expect(fn () => app(VoucherService::class)->redeem($voucher->code, $third))
        ->toThrow(VoucherException::class, 'vouchers.error_empty');
});

it('confirms a pending booking once the voucher covers its deposit', function (): void {
    $booking = voucherStay(confirmed: false);

    expect($booking->status)->toBe(BookingStatus::Pending);

    app(VoucherService::class)->redeem(activeVoucher(20000)->code, $booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('reads a code the way a person types it, and says why a voucher cannot be used', function (): void {
    $voucher = activeVoucher();
    $sloppy = strtolower(str_replace('-', ' ', $voucher->code));

    expect(app(VoucherService::class)->redeem('  '.$sloppy.' ', voucherStay())->amount)->toBe(10000);

    $service = app(VoucherService::class);

    expect(fn () => $service->redeem('GV-NOPE-NOPE', voucherStay(email: 'a@example.com')))->toThrow(VoucherException::class, 'vouchers.error_unknown');

    $expired = activeVoucher();
    $expired->forceFill(['expires_on' => CarbonImmutable::today(config('doba.timezone'))->subDay()->toDateString()])->save();
    expect(fn () => $service->redeem($expired->code, voucherStay(email: 'b@example.com')))->toThrow(VoucherException::class, 'vouchers.error_expired');

    $void = $service->void(activeVoucher());
    expect(fn () => $service->redeem($void->code, voucherStay(email: 'c@example.com')))->toThrow(VoucherException::class, 'vouchers.error_void');

    $foreign = activeVoucher();
    $foreign->forceFill(['currency' => 'PLN'])->save();
    expect(fn () => $service->redeem($foreign->code, voucherStay(email: 'd@example.com')))->toThrow(VoucherException::class, 'vouchers.error_currency');

    $cancelled = app(BookingService::class)->transition(voucherStay(email: 'e@example.com'), BookingStatus::Cancelled, 'test');
    expect(fn () => $service->redeem(activeVoucher()->code, $cancelled))->toThrow(VoucherException::class, 'vouchers.error_booking_closed');
});

it('gets its money back when the payment is refunded', function (): void {
    $booking = voucherStay();
    $voucher = activeVoucher(10000);

    $redemption = app(VoucherService::class)->redeem($voucher->code, $booking);

    expect($voucher->fresh()->status)->toBe(GiftVoucher::REDEEMED);

    app(PaymentService::class)->refund(GatewayRegistry::make('voucher'), $redemption->payment);

    expect($voucher->fresh()->balance)->toBe(10000)
        ->and($voucher->fresh()->status)->toBe(GiftVoucher::ACTIVE)
        ->and($redemption->fresh()->restored_at)->not->toBeNull()
        ->and($booking->fresh()->paid_amount)->toBe(0)
        ->and($booking->fresh()->balance_due)->toBe($booking->total);
});

it('is redeemed by the guest on their booking page', function (): void {
    $booking = voucherStay();
    $voucher = activeVoucher(5000);
    $url = '/en/booking/manage/'.$booking->reference.'/'.$booking->manage_token;

    $this->get($url)->assertOk()->assertSee('Pay with a gift voucher');

    $this->post($url.'/voucher', ['voucher_code' => 'GV-XXXX-XXXX'])->assertRedirect($url)->assertSessionHas('booking_error');
    $this->post($url.'/voucher', ['voucher_code' => $voucher->code])->assertRedirect($url)->assertSessionHas('voucher_redeemed');

    expect($booking->fresh()->paid_amount)->toBe(5000);

    // A wrong token is a 404, not a way to try codes against a booking.
    $this->post('/en/booking/manage/'.$booking->reference.'/wrong/voucher', ['voucher_code' => $voucher->code])->assertNotFound();
});

it('is sold and managed at the desk, behind the admin session', function (): void {
    $this->get('/admin/vouchers')->assertRedirect('/admin/login');
    $this->post('/admin/vouchers', ['amount' => 50, 'buyer_name' => 'X', 'locale' => 'en'])->assertRedirect('/admin/login');

    $admin = User::factory()->create();

    // Whole currency at the desk, minor units in the books; active at once.
    $this->actingAs($admin)->post('/admin/vouchers', [
        'amount' => '75', 'buyer_name' => 'Walk-in', 'locale' => 'en', 'internal_note' => 'paid cash',
    ])->assertRedirect('/admin/vouchers');

    $voucher = GiftVoucher::sole();

    expect($voucher->initial_amount)->toBe(7500)
        ->and($voucher->status)->toBe(GiftVoucher::ACTIVE)
        ->and($voucher->sold_via)->toBe('desk');

    $this->actingAs($admin)->get('/admin/vouchers')->assertOk()->assertSee($voucher->code)->assertSee('paid cash');
    $this->actingAs($admin)->get('/admin/vouchers/'.$voucher->id.'.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get('/admin/logout');

    $this->actingAs($admin)->post('/admin/vouchers/instructions', ['payment_instructions' => 'Pay at the desk.'])->assertSessionHas('saved');
    expect(app(HotelSettings::class)->get('vouchers.payment_instructions'))->toBe('Pay at the desk.');

    $this->actingAs($admin)->post('/admin/vouchers/'.$voucher->id.'/void')->assertSessionHas('saved');
    expect($voucher->fresh()->status)->toBe(GiftVoucher::VOID);
});
