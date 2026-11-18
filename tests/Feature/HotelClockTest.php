<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\GiftVoucher;
use App\Models\PromoCode;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Hotel\Clock;

/**
 * One clock for the whole system: the hotel's.
 *
 * The guest site used to switch PHP's timezone to the hotel's while cron,
 * the admin and webhooks stayed on UTC — so a hold written by a guest's
 * request was read an hour (or five) out by the command that expires it.
 */
function clockRoom(): RoomType
{
    $type = RoomType::create(['code' => 'CLK', 'base_occupancy' => 2, 'max_occupancy' => 2, 'default_rate' => 10000, 'total_units' => 2]);
    $type->translations()->create(['locale' => 'en', 'slug' => 'clk', 'name' => 'Double']);

    foreach (range(0, 30) as $i) {
        Availability::create(['room_type_id' => $type->id, 'date' => Clock::today()->addDays($i)->toDateString(), 'allotment' => 2]);
    }

    return $type;
}

/** @return array<string,mixed> */
function configAfterEnv(string $file, ?string $app, ?string $doba): array
{
    foreach (['APP_TIMEZONE' => $app, 'DOBA_TIMEZONE' => $doba] as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    return require config_path($file);
}

afterEach(function (): void {
    configAfterEnv('doba.php', null, null);
});

it('runs every part of the system on the same clock', function (): void {
    expect(config('app.timezone'))->toBe(config('doba.timezone'))
        ->and(date_default_timezone_get())->toBe(config('doba.timezone'));

    // And a guest's page view leaves it where it was.
    $this->get('/en')->assertOk();

    expect(date_default_timezone_get())->toBe(config('app.timezone'));
});

it('honours the timezone the wizard and the settings screen have always written', function (): void {
    // They wrote APP_TIMEZONE; nothing read it, and .env.example pinned
    // DOBA_TIMEZONE to Berlin. A hotel in New York ran on Berlin's day.
    expect(configAfterEnv('doba.php', 'America/New_York', 'Europe/Berlin')['timezone'])->toBe('America/New_York')
        ->and(configAfterEnv('app.php', 'America/New_York', 'Europe/Berlin')['timezone'])->toBe('America/New_York')
        // "UTC" there is what the settings screen wrote back by itself.
        ->and(configAfterEnv('doba.php', 'UTC', 'Europe/Vienna')['timezone'])->toBe('Europe/Vienna')
        ->and(configAfterEnv('app.php', null, 'Asia/Tokyo')['timezone'])->toBe('Asia/Tokyo')
        ->and(configAfterEnv('doba.php', null, null)['timezone'])->toBe('Europe/Berlin');
});

it('shows the hotel timezone on the settings screen and saves it where it is read', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin/settings')
        ->assertOk()
        ->assertSee('<option value="'.config('doba.timezone').'" selected', false);
});

it('keeps a hold for its twenty minutes wherever the hotel is', function (string $timezone): void {
    Clock::use($timezone);

    $type = clockRoom();
    $checkIn = Clock::today()->addDays(5);

    $booking = app(BookingService::class)->place($type, $checkIn, $checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2);

    $this->artisan('holds:release')->assertSuccessful();
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);

    $this->travel((int) config('doba.booking.hold_minutes') - 1)->minutes();
    $this->artisan('holds:release')->assertSuccessful();
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);

    $this->travel(2)->minutes();
    $this->artisan('holds:release')->assertSuccessful();
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
})->with(['Europe/Berlin', 'America/New_York', 'Asia/Tokyo', 'Pacific/Auckland']);

it('accepts a voucher and a promo code for the whole of their last day', function (string $timezone): void {
    Clock::use($timezone);
    $today = Clock::today();

    foreach ([1, 12, 23] as $hour) {
        $this->travelTo($today->setTime($hour, 30));

        $voucher = new GiftVoucher(['expires_on' => $today->toDateString()]);
        $code = new PromoCode(['is_active' => true, 'valid_from' => $today->toDateString(), 'valid_to' => $today->toDateString()]);

        expect($voucher->isExpired())->toBeFalse("voucher at {$hour}:30 in {$timezone}")
            ->and($code->rejectionReason($today->addDays(3), 2, 10000, []))->toBeNull("code at {$hour}:30 in {$timezone}");
    }

    $this->travelTo($today->addDay()->setTime(0, 30));

    expect((new GiftVoucher(['expires_on' => $today->toDateString()]))->isExpired())->toBeTrue();
})->with(['Europe/Berlin', 'America/Los_Angeles', 'Asia/Tokyo']);

it('counts the free-cancellation window back from midnight at the hotel', function (): void {
    Clock::use('America/New_York');

    $type = clockRoom();
    $checkIn = Clock::today()->addDays(5);
    $service = app(BookingService::class);

    $booking = $service->place($type, $checkIn, $checkIn->addDays(1),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2);
    $booking->rooms()->update(['cancellation_hours_snapshot' => 48, 'refundable_snapshot' => true]);
    $booking->forceFill(['paid_amount' => $booking->total])->save();
    $booking->load('rooms');

    $deadline = Clock::startOfDay($checkIn)->subHours(48);

    expect($service->refundableAmount($booking, $deadline->subMinute()))->toBe($booking->total)
        ->and($service->refundableAmount($booking, $deadline->addMinute()))->toBe(0);
});
