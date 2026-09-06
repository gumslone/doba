<?php

declare(strict_types=1);

use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Two rooms, one booking (§6): a family no longer books twice.
 */
function familyRoom(int $units = 3): RoomType
{
    $roomType = RoomType::query()->firstOr(fn () => RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'max_adults' => 2, 'max_children' => 1,
        'default_rate' => 10000, 'total_units' => $units,
    ]));
    $roomType->translations()->firstOrCreate(['locale' => 'en'], ['slug' => 'double', 'name' => 'Double']);

    foreach (range(0, 20) as $i) {
        Availability::firstOrCreate(
            ['room_type_id' => $roomType->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString()],
            ['allotment' => $units],
        );
    }

    return $roomType;
}

function familyStay(int $units): array
{
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    return [
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(2)->toDateString(),
        // Two adults per room: a party must fit the rooms it asks for, and
        // the engine rightly refuses four adults in one double.
        'adults' => 2 * $units, 'children' => 0, 'units' => $units,
    ];
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en']);
    config()->set('doba.payment.gateway', 'manual');
});

it('offers only categories that can supply the rooms asked for', function (): void {
    familyRoom(units: 3);

    // Two of three units already sold on night +10.
    Availability::query()->where('date', CarbonImmutable::today(config('doba.timezone'))->addDays(10)->toDateString())
        ->update(['booked' => 2]);

    // One room: still an offer. Two rooms: not for these dates.
    $this->get('/en/booking/search?'.http_build_query(familyStay(1)))->assertOk()->assertSee('Double');
    $this->get('/en/booking/search?'.http_build_query(familyStay(2)))->assertOk()->assertDontSee('Double');
});

it('prices the stay for the rooms asked for, and says so', function (): void {
    $room = familyRoom();

    $this->get('/en/booking/checkout?'.http_build_query(familyStay(2) + ['room_type' => $room->id]))
        ->assertOk()
        ->assertSee('for 2 rooms')
        // 2 nights × €100 × 2 rooms.
        ->assertSee(e(Money::format(40000)), false);
});

it('books two rooms as one booking, taking two units of every night', function (): void {
    $room = familyRoom();

    $this->post('/en/booking', familyStay(2) + [
        'room_type' => $room->id,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'anna@example.com',
        'terms' => '1',
    ]);

    $booking = Booking::sole();

    expect($booking->rooms()->count())->toBe(2)
        ->and($booking->subtotal)->toBe(40000)
        // The party is split across the rooms, not doubled.
        ->and($booking->rooms->sum('adults'))->toBe(4)
        // And the calendar shows two units gone on each night.
        ->and(Availability::query()->where('date', $booking->check_in->toDateString())->sole()->booked)->toBe(2);
});

it('refuses two rooms where only one is free, leaving nothing behind', function (): void {
    $room = familyRoom(units: 1);

    $this->post('/en/booking', familyStay(2) + [
        'room_type' => $room->id,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'anna@example.com',
        'terms' => '1',
    ])->assertRedirect();

    expect(Booking::query()->count())->toBe(0);
});

it('leaves the single-room URLs exactly as they were', function (): void {
    $room = familyRoom();

    // No `units` in a single-room link: every URL a guest bookmarked
    // before rooms could be counted still means what it meant.
    $this->get('/en/booking/search?'.http_build_query(familyStay(1)))
        ->assertOk()
        ->assertDontSee('units=', false)
        ->assertSee('room_type='.$room->id, false);
});
