<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Booking\NoAvailabilityException;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use Carbon\CarbonImmutable;

function guestData(array $overrides = []): array
{
    return array_merge([
        'email' => 'Anna@Example.com',
        'first_name' => 'Anna',
        'last_name' => 'Kowalska',
    ], $overrides);
}

function nightRow(RoomType $roomType, CarbonImmutable $date, array $overrides = []): Availability
{
    return Availability::updateOrCreate(
        ['room_type_id' => $roomType->id, 'date' => $date->toDateString()],
        array_merge(['allotment' => $roomType->total_units], $overrides)
    );
}

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'default_rate' => 10000,
        'total_units' => 2,
    ]);

    $this->checkIn = CarbonImmutable::today()->addDays(14);

    // Nights + checkout boundary, as availability:extend would generate.
    foreach (range(0, 3) as $i) {
        nightRow($this->roomType, $this->checkIn->addDays($i));
    }

    $this->service = app(BookingService::class);
});

it('creates a pending booking with holds, snapshots and incremented held counters', function (): void {
    $booking = $this->service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(3),
        guestData(), adults: 2
    );

    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->nights)->toBe(3)
        ->and($booking->subtotal)->toBe(30000)
        ->and($booking->reference)->toMatch('/^[A-Z]{3}-\d{4}-\d{4}$/')
        ->and(strlen($booking->manage_token))->toBe(40);

    // One booking_room, three price-snapshot nights.
    expect($booking->rooms)->toHaveCount(1)
        ->and($booking->rooms->first()->nights)->toHaveCount(3)
        ->and($booking->rooms->first()->price_total)->toBe(30000);

    // held incremented on the three sold nights, never the checkout row.
    expect(Availability::query()->where('held', '>', 0)->count())->toBe(3)
        ->and(nightRow($this->roomType, $this->checkIn->addDays(3))->held)->toBe(0);

    // A hold row per night with a future expiry.
    expect($booking->holds)->toHaveCount(3)
        ->and($booking->holds->first()->expires_at->isFuture())->toBeTrue();

    // History starts at pending.
    expect($booking->statusHistory->first())
        ->from_status->toBeNull()
        ->to_status->toBe(BookingStatus::Pending);
});

it('snapshots the resolved price per night, not the default rate', function (): void {
    nightRow($this->roomType, $this->checkIn->addDay(), ['price' => 8000]); // manual override

    Season::create([
        'name' => 'Peak', 'priority' => 5,
        'starts_on' => $this->checkIn->addDays(2)->toDateString(),
        'ends_on' => $this->checkIn->addDays(2)->toDateString(),
    ])->rates()->create([
        'room_type_id' => $this->roomType->id,
        'weekday_mask' => SeasonRate::ALL_WEEK,
        'price' => 15000,
    ]);

    $booking = $this->service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(3),
        guestData(), adults: 2
    );

    expect($booking->rooms->first()->nights->pluck('price')->all())
        ->toBe([10000, 8000, 15000])
        ->and($booking->subtotal)->toBe(33000);
});

it('refuses the last-room race loser with NoAvailabilityException, leaving nothing behind', function (): void {
    // Two units: two bookings fit, the third does not.
    $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);
    $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(['email' => 'b@example.com']), adults: 2);

    expect(fn () => $this->service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(2),
        guestData(['email' => 'c@example.com']), adults: 2
    ))->toThrow(NoAvailabilityException::class);

    // The failed attempt rolled back whole: no booking, no orphan holds.
    expect(Booking::count())->toBe(2)
        ->and(nightRow($this->roomType, $this->checkIn)->held)->toBe(2);
});

it('treats a missing night row as unavailable, never bookable', function (): void {
    expect(fn () => $this->service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(10),
        guestData(), adults: 2
    ))->toThrow(NoAvailabilityException::class);
});

it('confirms by converting held to booked and releasing the holds', function (): void {
    $booking = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);

    $this->service->transition($booking, BookingStatus::Confirmed);

    $row = nightRow($this->roomType, $this->checkIn);

    expect($row->held)->toBe(0)
        ->and($row->booked)->toBe(1)
        ->and($booking->fresh())
        ->status->toBe(BookingStatus::Confirmed)
        ->confirmed_at->not->toBeNull();

    expect($booking->holds()->unreleased()->count())->toBe(0);

    // Repeat guest history (§5).
    expect(Guest::first())
        ->stays_count->toBe(1)
        ->total_spent->toBe(20000);
});

it('re-acquires inventory when confirming after the hold was released — the late-webhook path', function (): void {
    $booking = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);

    // The release command has already freed the hold (payment webhook
    // arrives on the far side of the 20-minute window, §6).
    $this->travel(30)->minutes();
    $this->artisan('holds:release');

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and(nightRow($this->roomType, $this->checkIn)->held)->toBe(0);

    // Room still free → a fresh pending booking for the same stay confirms
    // by re-acquiring, not by trusting the dead hold.
    $retry = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);
    $this->travel(30)->minutes();
    $this->artisan('holds:release');

    expect(fn () => $this->service->transition($retry->fresh(), BookingStatus::Confirmed))
        ->toThrow(InvalidArgumentException::class); // already cancelled — dead end

    // The genuine re-acquire: hold released but booking still pending.
    $third = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);
    $third->holds()->update(['released_at' => now()]);
    Availability::query()->where('held', '>', 0)->decrement('held');

    $this->service->transition($third->fresh(), BookingStatus::Confirmed);

    expect(nightRow($this->roomType, $this->checkIn)->booked)->toBe(1);
});

it('refuses to confirm when the room was resold after the hold died', function (): void {
    $booking = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);

    // Hold freed, then both units resold to someone else.
    $booking->holds()->update(['released_at' => now()]);
    Availability::query()->where('held', '>', 0)->decrement('held');
    Availability::query()
        ->where('room_type_id', $this->roomType->id)
        ->whereIn('date', [$this->checkIn->toDateString(), $this->checkIn->addDay()->toDateString()])
        ->update(['booked' => 2, 'held' => 0]);

    // Money taken, room gone → the caller must refund, never confirm (§6).
    expect(fn () => $this->service->transition($booking->fresh(), BookingStatus::Confirmed))
        ->toThrow(NoAvailabilityException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('releases booked inventory when a confirmed booking is cancelled', function (): void {
    $booking = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);
    $this->service->transition($booking, BookingStatus::Confirmed);
    $this->service->transition($booking->fresh(), BookingStatus::Cancelled, 'Guest request');

    $row = nightRow($this->roomType, $this->checkIn);

    expect($row->booked)->toBe(0)
        ->and($row->held)->toBe(0)
        ->and($booking->fresh())
        ->status->toBe(BookingStatus::Cancelled)
        ->cancellation_reason->toBe('Guest request');
});

it('releases held inventory when staff cancel a pending booking — the §6 leak trap', function (): void {
    $booking = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);

    $this->service->transition($booking, BookingStatus::Cancelled, 'Staff cancellation', userId: null);

    expect(nightRow($this->roomType, $this->checkIn)->held)->toBe(0)
        ->and($booking->holds()->unreleased()->count())->toBe(0);
});

it('keeps inventory consumed on a no-show', function (): void {
    $booking = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);
    $this->service->transition($booking, BookingStatus::Confirmed);
    $this->service->transition($booking->fresh(), BookingStatus::NoShow);

    // no_show is a consuming status (§6) — the room was blocked all night.
    expect(nightRow($this->roomType, $this->checkIn)->booked)->toBe(1);
});

it('refuses transitions the state machine does not allow', function (): void {
    $booking = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);

    expect(fn () => $this->service->transition($booking, BookingStatus::CheckedIn))
        ->toThrow(InvalidArgumentException::class);
});

it('writes the full audit trail across a booking lifetime', function (): void {
    $booking = $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDays(2), guestData(), adults: 2);
    $this->service->transition($booking, BookingStatus::Confirmed);
    $this->service->transition($booking->fresh(), BookingStatus::CheckedIn);
    $this->service->transition($booking->fresh(), BookingStatus::CheckedOut);

    expect($booking->statusHistory()->orderBy('id')->pluck('to_status')->all())
        ->toBe([BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::CheckedOut]);
});

it('deduplicates guests by lowercased email', function (): void {
    $this->service->place($this->roomType, $this->checkIn, $this->checkIn->addDay(), guestData(['email' => 'Anna@Example.com']), adults: 1);
    $this->service->place($this->roomType, $this->checkIn->addDay(), $this->checkIn->addDays(2), guestData(['email' => 'anna@example.COM']), adults: 1);

    expect(Guest::count())->toBe(1)
        ->and(Guest::first()->email)->toBe('anna@example.com')
        ->and(Booking::count())->toBe(2);
});

it('books multiple units of one room type as separate booking rooms', function (): void {
    $booking = $this->service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(2),
        guestData(), adults: 4, units: 2
    );

    expect($booking->rooms)->toHaveCount(2)
        ->and($booking->subtotal)->toBe(40000)
        ->and(nightRow($this->roomType, $this->checkIn)->held)->toBe(2);

    // Cancelling releases both units, not one.
    $this->service->transition($booking, BookingStatus::Cancelled);

    expect(nightRow($this->roomType, $this->checkIn)->held)->toBe(0);
});
