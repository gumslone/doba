<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Guests\GuestPrivacy;
use App\Mail\PostStay;
use App\Mail\PreArrival;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use App\Support\Mail\MailSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * The lifecycle mail nobody has to remember (§13).
 */
function hotelToday(): CarbonImmutable
{
    // The frame the command selects in. A UTC-framed fixture here would
    // put this suite in the same 22:00-to-midnight trap RatePlanTest
    // sat in.
    return CarbonImmutable::today(config('doba.timezone'));
}

function stayFor(CarbonImmutable $checkIn, int $nights = 2, string $status = 'confirmed', string $email = 'anna@example.com'): Booking
{
    $roomType = RoomType::create([
        'code' => 'DBL-'.uniqid(),
        'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 5,
    ]);

    foreach (range(-30, 30) as $i) {
        Availability::firstOrCreate(
            ['room_type_id' => $roomType->id, 'date' => hotelToday()->addDays($i)->toDateString()],
            ['allotment' => 5],
        );
    }

    $booking = app(BookingService::class)->place(
        $roomType,
        $checkIn->lessThan(hotelToday()) ? hotelToday() : $checkIn,
        ($checkIn->lessThan(hotelToday()) ? hotelToday() : $checkIn)->addDays($nights),
        ['email' => $email, 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: 2,
    );

    // Past stays cannot be placed through the engine (rightly), so the
    // dates are rewritten afterwards — the command reads the columns.
    $booking->forceFill([
        'status' => $status,
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays($nights)->toDateString(),
    ])->save();

    return $booking->fresh();
}

beforeEach(function (): void {
    Mail::fake();
    config()->set('doba.locales', ['en', 'de']);
    app(MailSettings::class)->confirm();
});

it('greets the stays arriving inside the window, once each', function (): void {
    $soon = stayFor(hotelToday()->addDays(2));
    $tomorrow = stayFor(hotelToday()->addDays(1), email: 'b@example.com');
    $farOut = stayFor(hotelToday()->addDays(20), email: 'c@example.com');
    $cancelled = stayFor(hotelToday()->addDays(2), status: 'cancelled', email: 'd@example.com');

    Artisan::call('doba:guest-mail');

    Mail::assertQueued(PreArrival::class, 2);
    Mail::assertQueued(PreArrival::class, fn (PreArrival $m): bool => $m->booking->is($soon));
    Mail::assertQueued(PreArrival::class, fn (PreArrival $m): bool => $m->booking->is($tomorrow));

    // The second run finds everyone stamped and mails nobody — the stamp
    // IS the idempotency, so a crashed-and-rerun command cannot repeat.
    Mail::fake();
    Artisan::call('doba:guest-mail');
    Mail::assertNothingQueued();

    expect($farOut->fresh()->pre_arrival_sent_at)->toBeNull()
        ->and($cancelled->fresh()->pre_arrival_sent_at)->toBeNull();
});

it('thanks the recently departed, checked out at the desk or not', function (): void {
    $checkedOut = stayFor(hotelToday()->subDays(3), 2, 'checked_out');
    // The desk never pressed the button, but the guest is gone all the same.
    $quietlyGone = stayFor(hotelToday()->subDays(4), 2, 'confirmed', 'b@example.com');
    // A month old: switching the feature on must not thank last season
    // in one avalanche.
    $ancient = stayFor(hotelToday()->subDays(40), 2, 'checked_out', 'c@example.com');
    $noShow = stayFor(hotelToday()->subDays(3), 2, 'no_show', 'd@example.com');

    Artisan::call('doba:guest-mail');

    Mail::assertQueued(PostStay::class, 2);
    Mail::assertQueued(PostStay::class, fn (PostStay $m): bool => $m->booking->is($checkedOut));
    Mail::assertQueued(PostStay::class, fn (PostStay $m): bool => $m->booking->is($quietlyGone));

    expect($ancient->fresh()->post_stay_sent_at)->toBeNull()
        ->and($noShow->fresh()->post_stay_sent_at)->toBeNull();
});

it('sends nothing while outgoing mail is unconfirmed, and says so', function (): void {
    stayFor(hotelToday()->addDays(2));

    app(MailSettings::class)->unconfirm();

    Artisan::call('doba:guest-mail');

    // The rule the mail screen is built around: mail is broken until a
    // human says a test arrived. A nightly job that "sent" hundreds of
    // messages into a dead transport is that rule's worst enemy.
    Mail::assertNothingQueued();

    expect(Artisan::output())->toContain('not confirmed')
        ->and(Booking::sole()->pre_arrival_sent_at)->toBeNull();
});

it('writes the mail in the language the guest booked in', function (): void {
    $booking = stayFor(hotelToday()->addDays(2));
    $booking->forceFill(['locale' => 'de', 'balance_due' => 15000])->save();

    $mailable = new PreArrival($booking->fresh());

    $rendered = $mailable->render();

    expect($mailable->envelope()->subject)->toContain('steht bevor')
        // The balance and the way to settle it, one click away — the
        // amount asserted through the same formatter the mail uses, so
        // this does not re-legislate how German prices are written.
        ->and($rendered)->toContain(e(Money::format(15000, 'EUR', 'de')))
        ->and($rendered)->toContain('offen')
        ->and($rendered)->toContain($booking->manage_token);
});

it('respects the config switches', function (): void {
    stayFor(hotelToday()->addDays(2));
    stayFor(hotelToday()->subDays(3), 2, 'checked_out', 'b@example.com');

    config()->set('doba.guest_mail.pre_arrival_days', 0);
    config()->set('doba.guest_mail.post_stay', false);

    Artisan::call('doba:guest-mail');

    Mail::assertNothingQueued();
});

it('skips a stay with no address instead of failing the whole run', function (): void {
    $withMail = stayFor(hotelToday()->addDays(2));

    $without = stayFor(hotelToday()->addDays(2), email: 'temp@example.com');
    $without->guest->forceFill(['email' => ''])->save();

    Artisan::call('doba:guest-mail');

    // An iCal-imported stay may have no address at all; the one guest we
    // can reach must still hear from us.
    Mail::assertQueued(PreArrival::class, 1);
    Mail::assertQueued(PreArrival::class, fn (PreArrival $m): bool => $m->booking->is($withMail));
});

it('never mails a guest who has been erased since their stay', function (): void {
    $booking = stayFor(hotelToday()->subDays(3), 2, 'checked_out');

    // Checked out Monday, erased Tuesday — the Wednesday thank-you run
    // must find nobody.
    app(GuestPrivacy::class)->erase($booking->guest);

    Artisan::call('doba:guest-mail');

    Mail::assertNothingQueued();
});
