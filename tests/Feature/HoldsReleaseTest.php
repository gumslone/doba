<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'default_rate' => 10000,
        'total_units' => 2,
    ]);

    $this->checkIn = CarbonImmutable::today()->addDays(14);

    foreach (range(0, 2) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->checkIn->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    $this->booking = app(BookingService::class)->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2
    );
});

it('cancels the pending booking and decrements held once the hold expires', function (): void {
    $this->travel(config('doba.booking.hold_minutes') + 1)->minutes();

    $this->artisan('holds:release')->assertSuccessful();

    expect($this->booking->fresh())
        ->status->toBe(BookingStatus::Cancelled)
        ->cancellation_reason->toBe('Hold expired');

    expect(Availability::query()->where('held', '>', 0)->count())->toBe(0)
        ->and($this->booking->holds()->unreleased()->count())->toBe(0);
});

it('leaves unexpired holds alone', function (): void {
    $this->artisan('holds:release')->assertSuccessful();

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Pending)
        ->and(Availability::query()->where('date', $this->checkIn->toDateString())->first()->held)->toBe(1);
});

it('is idempotent — a second run releases nothing twice', function (): void {
    $this->travel(config('doba.booking.hold_minutes') + 1)->minutes();

    $this->artisan('holds:release');
    $this->artisan('holds:release');

    // A double decrement would push held to -1 and trip the CHECK
    // constraint; the count staying at zero proves neither happened.
    expect(Availability::query()->where('held', '<', 0)->count())->toBe(0)
        ->and($this->booking->statusHistory()->where('to_status', BookingStatus::Cancelled)->count())->toBe(1);
});

it('never touches a confirmed booking, even with stale hold rows', function (): void {
    app(BookingService::class)->transition($this->booking, BookingStatus::Confirmed);

    $this->travel(config('doba.booking.hold_minutes') + 1)->minutes();
    $this->artisan('holds:release');

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(Availability::query()->where('date', $this->checkIn->toDateString())->first()->booked)->toBe(1);
});
