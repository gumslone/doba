<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Mail\CheckoutReminder;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use App\Support\Mail\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

/**
 * The unfinished-booking reminder (§13): once, an hour later, only while
 * the room can still be had — and off unless the hotel said otherwise.
 */
function abandonedCheckout(string $email = 'anna@example.com', int $minutesAgo = 90): Booking
{
    $roomType = RoomType::query()->where('code', 'REC')->firstOr(function (): RoomType {
        $type = RoomType::create(['code' => 'REC', 'base_occupancy' => 2, 'max_occupancy' => 2, 'default_rate' => 10000, 'total_units' => 1]);
        $type->translations()->create(['locale' => 'en', 'slug' => 'rec', 'name' => 'Garden double']);

        foreach (range(0, 20) as $i) {
            Availability::create(['room_type_id' => $type->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString(), 'allotment' => 1]);
        }

        return $type;
    });

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => $email, 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: 2,
    );

    // Exactly what holds:release does when the clock runs out.
    $booking = app(BookingService::class)->transition($booking, BookingStatus::Cancelled, 'Hold expired');
    $booking->forceFill(['cancelled_at' => CarbonImmutable::now()->subMinutes($minutesAgo)])->save();

    return $booking->fresh();
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    config()->set('doba.guest_mail.recovery', true);
    Mail::fake();
    app(MailSettings::class)->confirm();
});

it('is off unless the hotel switched it on', function (): void {
    config()->set('doba.guest_mail.recovery', false);
    $booking = abandonedCheckout();

    $this->artisan('doba:recovery-mail')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($booking->fresh()->recovery_sent_at)->toBeNull();
});

it('reminds the guest once, with a link back to the same room and dates', function (): void {
    $booking = abandonedCheckout();

    $this->artisan('doba:recovery-mail')->assertSuccessful();
    $this->artisan('doba:recovery-mail')->assertSuccessful();

    Mail::assertQueued(CheckoutReminder::class, 1);
    Mail::assertQueued(CheckoutReminder::class, function (CheckoutReminder $mail) use ($booking): bool {
        $html = $mail->render();

        return $mail->hasTo('anna@example.com')
            && $mail->hasSubject('Your room at '.config('app.name').' is still free')
            && str_contains($html, 'check_in='.$booking->check_in->toDateString())
            && str_contains($html, 'room_type='.$booking->rooms->first()->room_type_id)
            && str_contains($html, 'Garden double')
            // Says plainly that nothing was taken.
            && str_contains($html, 'nothing was charged');
    });

    expect($booking->fresh()->recovery_sent_at)->not->toBeNull();
});

it('waits about an hour, and gives up after a day', function (): void {
    $fresh = abandonedCheckout('fresh@example.com', minutesAgo: 20);
    $this->artisan('doba:recovery-mail');
    Mail::assertNothingQueued();
    expect($fresh->fresh()->recovery_sent_at)->toBeNull();

    $fresh->forceFill(['cancelled_at' => CarbonImmutable::now()->subDays(2)])->save();
    $this->artisan('doba:recovery-mail');
    Mail::assertNothingQueued();
});

it('only follows a hold that expired by itself', function (): void {
    $booking = abandonedCheckout();
    $booking->forceFill(['cancellation_reason' => 'Guest cancelled'])->save();

    $desk = abandonedCheckout('desk@example.com');
    $desk->forceFill(['source' => 'phone'])->save();

    $this->artisan('doba:recovery-mail');

    Mail::assertNothingQueued();
});

it('stays silent when the room has gone, and when the guest came back by themselves', function (): void {
    $booking = abandonedCheckout();

    // Somebody else took the last room for those nights.
    $other = app(BookingService::class)->place(
        $booking->rooms->first()->roomType, $booking->check_in, $booking->check_out,
        ['email' => 'other@example.com', 'first_name' => 'O', 'last_name' => 'T'], adults: 2,
    );
    app(BookingService::class)->transition($other, BookingStatus::Confirmed, 'test');

    $this->artisan('doba:recovery-mail');
    Mail::assertNotQueued(CheckoutReminder::class);   // confirming a booking queues its own mail
    // Not stamped: a cancellation may free the room again within the day.
    expect($booking->fresh()->recovery_sent_at)->toBeNull();

    // The same guest finishing a later attempt settles it for good.
    app(BookingService::class)->transition($other, BookingStatus::Cancelled, 'test');
    $again = app(BookingService::class)->place(
        $booking->rooms->first()->roomType, $booking->check_in, $booking->check_out,
        ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'], adults: 2,
    );
    app(BookingService::class)->transition($again, BookingStatus::Confirmed, 'test');

    $this->artisan('doba:recovery-mail');
    Mail::assertNotQueued(CheckoutReminder::class);   // confirming a booking queues its own mail
    expect($booking->fresh()->recovery_sent_at)->not->toBeNull();
});

it('never writes to an erased guest or a made-up desk address, nor while mail is unconfirmed', function (): void {
    $erased = abandonedCheckout('erased@example.com');
    $erased->guest->forceFill(['anonymised_at' => now()])->save();

    app(MailSettings::class)->unconfirm();
    abandonedCheckout('real@example.com');
    $this->artisan('doba:recovery-mail')->expectsOutputToContain('not confirmed');
    Mail::assertNothingQueued();
});

it('writes in the language the guest was booking in', function (): void {
    $booking = abandonedCheckout();
    $booking->forceFill(['locale' => 'de'])->save();

    $mail = new CheckoutReminder($booking->fresh());

    expect($mail->envelope()->subject)->toContain('ist noch frei')
        ->and($mail->render())->toContain('Buchung abschließen')
        ->and($mail->render())->toContain('/de/');
});

it('is on the schedule, every ten minutes', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'doba:recovery-mail'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *');
});
