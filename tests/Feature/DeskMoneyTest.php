<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Payments\PaymentService;
use App\Domain\Vouchers\VoucherService;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Mail\BookingCancelled;
use App\Mail\BookingConfirmed;
use App\Mail\BookingNotice;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\Clock;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use Illuminate\Support\Facades\Mail;

/**
 * The desk's money, and the end of a stay that never happened (§8, §12).
 *
 * Until these existed a hotel could take a booking and do nothing else
 * with it: no way to say a guest had paid in cash, to give money back, to
 * cancel for a guest on the phone or to mark one who never came — and a
 * guest who cancelled heard nothing and was refunded nothing.
 */
function tillRoom(): RoomType
{
    return RoomType::query()->where('code', 'DSK')->firstOr(function (): RoomType {
        $type = RoomType::create(['code' => 'DSK', 'base_occupancy' => 2, 'max_occupancy' => 2, 'default_rate' => 10000, 'total_units' => 6]);
        $type->translations()->create(['locale' => 'en', 'slug' => 'dsk', 'name' => 'Double']);

        foreach (range(0, 30) as $i) {
            Availability::create(['room_type_id' => $type->id, 'date' => Clock::today()->addDays($i)->toDateString(), 'allotment' => 6]);
        }

        return $type;
    });
}

/**
 * A confirmed two-night stay (200.00), refundable until 48h before arrival
 * unless told otherwise.
 */
function tillStay(int $daysAhead = 10, bool $refundable = true, string $email = 'guest@example.com', bool $confirmed = true): Booking
{
    $checkIn = Clock::today()->addDays($daysAhead);

    $booking = app(BookingService::class)->place(
        tillRoom(), $checkIn, $checkIn->addDays(2),
        ['email' => $email, 'first_name' => 'Dora', 'last_name' => 'Desk'], adults: 2,
    );

    $booking->rooms()->update(['refundable_snapshot' => $refundable, 'cancellation_hours_snapshot' => 48]);

    return $confirmed
        ? app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test', userId: 1)->fresh()
        : $booking->fresh();
}

function tillManageUrl(Booking $booking): string
{
    return "/en/booking/manage/{$booking->reference}/{$booking->manage_token}";
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    config()->set('doba.features.vouchers', true);
    Mail::fake();
    app(MailSettings::class)->confirm();
    Setting::put('contact', 'email', 'desk@alpenhof.example');
    HotelSettings::flush();
    app(HotelSettings::class)->refresh();

    $this->admin = User::factory()->create();
});

it('records cash taken at the desk, and the guest stops being asked for it', function (): void {
    $booking = tillStay();

    $this->actingAs($this->admin)
        ->post("/admin/bookings/{$booking->id}/payments", ['amount' => '120.00', 'method' => 'cash', 'note' => 'Deposit on arrival'])
        ->assertRedirect()
        ->assertSessionHas('saved');

    $booking->refresh();

    expect($booking->paid_amount)->toBe(12000)
        ->and($booking->balance_due)->toBe(8000);

    $payment = Payment::query()->where('gateway', 'desk')->sole();

    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->payload['method'])->toBe('cash')
        ->and($payment->payload['user_id'])->toBe($this->admin->id);

    $this->actingAs($this->admin)->get("/admin/bookings/{$booking->id}/edit")
        ->assertOk()
        ->assertSee(__('admin.payment_via_desk'))
        ->assertSee('Deposit on arrival');

    // The guest's own page says paid — not "due now", which is what the
    // line was labelled when it meant money the hotel already had.
    auth()->logout();
    $this->get(tillManageUrl($booking))
        ->assertOk()
        ->assertSee(__('booking.paid'))
        ->assertDontSee(__('booking.deposit'));
});

it('will not record more than is owed, or anything on a cancelled stay', function (): void {
    $booking = tillStay();

    $this->actingAs($this->admin)
        ->post("/admin/bookings/{$booking->id}/payments", ['amount' => '250.00', 'method' => 'card'])
        ->assertSessionHasErrors('amount');

    app(BookingService::class)->transition($booking, BookingStatus::Cancelled, 'test', userId: 1);

    $this->actingAs($this->admin)
        ->post("/admin/bookings/{$booking->id}/payments", ['amount' => '50.00', 'method' => 'card'])
        ->assertSessionHasErrors('amount');

    expect(Payment::query()->where('gateway', 'desk')->count())->toBe(0);
});

it('refunds a desk payment in parts, and never more than was paid', function (): void {
    $booking = tillStay();
    $payment = app(PaymentService::class)->recordAtDesk($booking, 20000, 'card');

    $refund = fn (string $amount) => $this->actingAs($this->admin)
        ->post("/admin/bookings/{$booking->id}/payments/{$payment->id}/refund", ['amount' => $amount]);

    $refund('50.00')->assertSessionHas('saved');

    expect($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($booking->fresh()->paid_amount)->toBe(15000)
        ->and($booking->fresh()->balance_due)->toBe(5000);

    $refund('200.00')->assertSessionHasErrors('amount');
    $refund('150.00')->assertSessionHas('saved');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($booking->fresh()->paid_amount)->toBe(0)
        ->and(app(PaymentService::class)->refundable($payment->fresh()))->toBe(0);
});

it('will not refund a payment through another booking\'s page', function (): void {
    $booking = tillStay();
    $other = tillStay(email: 'other@example.com');
    $payment = app(PaymentService::class)->recordAtDesk($booking, 20000, 'cash');

    $this->actingAs($this->admin)
        ->post("/admin/bookings/{$other->id}/payments/{$payment->id}/refund", ['amount' => '10.00'])
        ->assertNotFound();
});

it('puts the money back on the voucher when a guest cancels in their free window, and tells them', function (): void {
    $booking = tillStay();
    $voucher = app(VoucherService::class)->issue(['amount' => 15000, 'buyer_name' => 'Tante Erna', 'buyer_email' => 'erna@example.com', 'locale' => 'en'], mail: false);
    app(VoucherService::class)->redeem($voucher->code, $booking);

    expect($voucher->fresh()->balance)->toBe(0)
        ->and($booking->fresh()->paid_amount)->toBe(15000);

    $this->post(tillManageUrl($booking).'/cancel')->assertSessionHas('booking_cancelled');

    $booking->refresh();

    // It used to stay spent: the stay was cancelled, the voucher was
    // empty, and nothing anywhere said the guest was owed anything.
    expect($voucher->fresh()->balance)->toBe(15000)
        ->and($voucher->fresh()->status)->toBe('active')
        ->and($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->paid_amount)->toBe(0)
        ->and($booking->refund_due)->toBe(0)
        ->and($booking->balance_due)->toBe(0);

    Mail::assertQueued(BookingCancelled::class, fn (BookingCancelled $mail): bool => $mail->hasTo('guest@example.com')
        && $mail->restoredToVoucher === 15000
        && str_contains($mail->render(), '€150'));

    Mail::assertQueued(BookingNotice::class, fn (BookingNotice $mail): bool => $mail->hasTo('desk@alpenhof.example')
        && $mail->event === BookingNotice::CANCELLED);
});

it('keeps a refund owed in front of the hotel until somebody makes it', function (): void {
    $booking = tillStay();
    $payment = app(PaymentService::class)->recordAtDesk($booking, 20000, 'card');

    $this->post(tillManageUrl($booking).'/cancel');

    $booking->refresh();

    // Card money does not move by itself — but the debt is written down.
    expect($booking->refund_due)->toBe(20000)
        ->and($booking->paid_amount)->toBe(20000)
        ->and($booking->balance_due)->toBe(0);

    Mail::assertQueued(BookingNotice::class, fn (BookingNotice $mail): bool => str_contains($mail->render(), '€200'));
    Mail::assertQueued(BookingCancelled::class, fn (BookingCancelled $mail): bool => str_contains(
        $mail->render(), strip_tags(__('mail.cancelled_refund', ['amount' => '€200']))
    ));

    $this->actingAs($this->admin)->get("/admin/bookings/{$booking->id}/edit")
        ->assertOk()
        ->assertSee(__('admin.booking_refund_owed', ['amount' => '€200']));

    $this->actingAs($this->admin)
        ->post("/admin/bookings/{$booking->id}/payments/{$payment->id}/refund", ['amount' => '200.00'])
        ->assertSessionHas('saved');

    expect($booking->fresh()->refund_due)->toBe(0)
        ->and($booking->fresh()->balance_due)->toBe(0);   // cancelled: giving it back is not a debt

    $this->actingAs($this->admin)->get("/admin/bookings/{$booking->id}/edit")
        ->assertDontSee(__('admin.booking_refund_owed', ['amount' => '€200']));
});

it('owes nothing back on a non-refundable rate, and says so', function (): void {
    $booking = tillStay(refundable: false);
    app(PaymentService::class)->recordAtDesk($booking, 20000, 'card');

    $this->post(tillManageUrl($booking).'/cancel');

    expect($booking->fresh()->refund_due)->toBe(0);

    Mail::assertQueued(BookingCancelled::class, fn (BookingCancelled $mail): bool => str_contains(
        $mail->render(), strip_tags(__('mail.cancelled_no_refund', ['amount' => '€200']))
    ));
});

it('owes nothing back once the free window has closed', function (): void {
    $booking = tillStay(daysAhead: 1);   // 48 hours' notice is no longer possible
    app(PaymentService::class)->recordAtDesk($booking, 20000, 'cash');

    $this->post(tillManageUrl($booking).'/cancel');

    expect($booking->fresh()->refund_due)->toBe(0);
});

it('lets the desk cancel for a guest on the phone, with the reason in the history', function (): void {
    $booking = tillStay();

    $this->actingAs($this->admin)
        ->post("/admin/bookings/{$booking->id}/cancel", ['reason' => 'Guest is ill'])
        ->assertSessionHas('saved');

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancellation_reason)->toBe('Cancelled at the desk: Guest is ill')
        ->and($booking->statusHistory()->latest('id')->first()->user_id)->toBe($this->admin->id);

    // The guest is told. The desk is not mailed about its own click.
    Mail::assertQueued(BookingCancelled::class);
    Mail::assertNotQueued(BookingNotice::class);
});

it('marks a guest who never came, but not before they were due', function (): void {
    $future = tillStay(daysAhead: 3);

    $this->actingAs($this->admin)->post("/admin/bookings/{$future->id}/no-show")->assertSessionHasErrors('status');
    expect($future->fresh()->status)->toBe(BookingStatus::Confirmed);

    $tonight = tillStay(daysAhead: 0, email: 'late@example.com');

    $this->actingAs($this->admin)->post("/admin/bookings/{$tonight->id}/no-show")->assertSessionHas('saved');

    expect($tonight->fresh()->status)->toBe(BookingStatus::NoShow)
        // A no-show keeps the room: that is what the hotel charges for.
        ->and(Availability::query()->where('room_type_id', tillRoom()->id)->where('date', Clock::today()->toDateString())->first()->booked)->toBe(1);
});

it('holds a room for a caller until tomorrow, and can confirm it when they ring back', function (): void {
    $checkIn = Clock::today()->addDays(5);

    $this->actingAs($this->admin)->post('/admin/bookings', [
        'room_type_id' => tillRoom()->id,
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'first_name' => 'Carl', 'last_name' => 'Caller',
        'email' => 'carl@example.com', 'source' => 'phone', 'confirm' => '0',
    ])->assertRedirect();

    $booking = Booking::query()->latest('id')->first();
    expect($booking->status)->toBe(BookingStatus::Pending);

    // Twenty minutes was the website's hold; the desk's lasted no longer,
    // and nothing in the admin could confirm it once it had gone.
    $this->travel(3)->hours();
    $this->artisan('holds:release')->assertSuccessful();
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);

    $this->actingAs($this->admin)->get("/admin/bookings/{$booking->id}/edit")->assertOk()->assertSee(__('admin.booking_confirm'));
    $this->actingAs($this->admin)->post("/admin/bookings/{$booking->id}/confirm")->assertSessionHas('saved');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    // And an unanswered hold does still lapse.
    $other = tillStay(email: 'gone@example.com', confirmed: false);
    $other->holds()->update(['expires_at' => now()->addHours(24)]);
    $this->travel(25)->hours();
    $this->artisan('holds:release')->assertSuccessful();
    expect($other->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('tells the hotel about a booking it did not take itself, and not about one it did', function (): void {
    $checkIn = Clock::today()->addDays(8);

    $this->post('/en/booking', [
        'room_type' => tillRoom()->id,
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'children' => 0,
        'first_name' => 'Wanda', 'last_name' => 'Web', 'email' => 'wanda@example.com',
        'guest_notes' => 'A cot, please.', 'terms' => '1',
    ])->assertRedirect();

    Mail::assertQueued(BookingNotice::class, fn (BookingNotice $mail): bool => $mail->hasTo('desk@alpenhof.example')
        && $mail->event === BookingNotice::BOOKED
        && str_contains($mail->render(), 'A cot, please.')
        && str_contains($mail->render(), '/admin/bookings/'));

    Mail::fake();

    $this->actingAs($this->admin)->post('/admin/bookings', [
        'room_type_id' => tillRoom()->id,
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'first_name' => 'Paul', 'last_name' => 'Phone',
        'email' => 'paul@example.com', 'source' => 'phone', 'confirm' => '1',
    ])->assertRedirect();

    Mail::assertQueued(BookingConfirmed::class);
    Mail::assertNotQueued(BookingNotice::class);

    // And a hotel that does not want them gets none.
    Mail::fake();
    config(['doba.notify.bookings' => false]);
    app(BookingService::class)->transition(tillStay(email: 'quiet@example.com', confirmed: false), BookingStatus::Confirmed, 'paid');

    Mail::assertNotQueued(BookingNotice::class);
});

it('never mails the stand-in address of a walk-in who gave none', function (): void {
    $checkIn = Clock::today()->addDays(2);

    $this->actingAs($this->admin)->post('/admin/bookings', [
        'room_type_id' => tillRoom()->id,
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(1)->toDateString(),
        'adults' => 1, 'first_name' => 'Willi', 'last_name' => 'Walkin',
        'email' => '', 'source' => 'walk_in', 'confirm' => '1',
    ])->assertRedirect();

    $booking = Booking::query()->latest('id')->first();

    expect($booking->guest->email)->toEndWith('@'.Guest::NO_EMAIL_DOMAIN)
        ->and($booking->guest->isMailable())->toBeFalse();

    Mail::assertNothingQueued();

    $this->actingAs($this->admin)->post("/admin/bookings/{$booking->id}/cancel")->assertSessionHas('saved');

    Mail::assertNothingQueued();
});

it('does not make a regular out of somebody who booked and cancelled', function (): void {
    config(['doba.loyalty.discount_bps' => 1000, 'doba.loyalty.min_stays' => 1]);

    $first = tillStay(email: 'trick@example.com');
    $this->post(tillManageUrl($first).'/cancel');

    $checkIn = Clock::today()->addDays(15);
    $second = app(BookingService::class)->place(
        tillRoom(), $checkIn, $checkIn->addDays(2),
        ['email' => 'trick@example.com', 'first_name' => 'Dora', 'last_name' => 'Desk'], adults: 2, applyLoyalty: true,
    );

    expect($second->loyalty_discount)->toBe(0)
        ->and(Guest::query()->where('email', 'trick@example.com')->sole()->stays_count)->toBe(0);
});

it('shows nothing left to pay on a cancelled booking', function (): void {
    $booking = tillStay();

    $this->post(tillManageUrl($booking).'/cancel');

    $this->get(tillManageUrl($booking))
        ->assertOk()
        ->assertDontSee(__('booking.balance'));

    expect($booking->fresh()->balance_due)->toBe(0);
});
