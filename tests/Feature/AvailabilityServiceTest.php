<?php

declare(strict_types=1);

use App\Domain\Availability\AvailabilityService;
use App\Models\Availability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function makeRoomType(array $overrides = []): RoomType
{
    return RoomType::create(array_merge([
        'code' => 'RT-'.uniqid(),
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'max_adults' => 2,
        'max_children' => 1,
        'default_rate' => 10000,
        'total_units' => 5,
    ], $overrides));
}

/**
 * Rows for [$from … $from + $days] inclusive — mirroring availability:extend,
 * which always generates through the checkout boundary.
 */
function seedNights(RoomType $roomType, CarbonImmutable $from, int $days, array $overrides = []): void
{
    for ($i = 0; $i <= $days; $i++) {
        $date = $from->addDays($i)->toDateString();

        Availability::updateOrCreate(
            ['room_type_id' => $roomType->id, 'date' => $date],
            array_merge(['allotment' => $roomType->total_units], $overrides[$date] ?? [])
        );
    }
}

beforeEach(function (): void {
    $this->roomType = makeRoomType();
    $this->service = app(AvailabilityService::class);
    $this->checkIn = CarbonImmutable::today()->addDays(10);
});

it('accepts a plain available stay', function (): void {
    seedNights($this->roomType, $this->checkIn, 3);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeTrue();
});

it('rejects when any sold night is closed or sold out', function (): void {
    seedNights($this->roomType, $this->checkIn, 3, [
        $this->checkIn->addDay()->toDateString() => ['closed' => true],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeFalse();

    seedNights($this->roomType, $this->checkIn, 3, [
        $this->checkIn->addDay()->toDateString() => ['closed' => false, 'allotment' => 5, 'booked' => 3, 'held' => 2],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeFalse();
});

it('ignores closed and allotment on the checkout row', function (): void {
    // The guest leaves that morning: a stop-sell or a full house on the
    // departure date must not block the stay (§6's classic boundary bug).
    seedNights($this->roomType, $this->checkIn, 3, [
        $this->checkIn->addDays(3)->toDateString() => ['closed' => true, 'allotment' => 5, 'booked' => 5],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeTrue();
});

it('enforces closed_to_departure on the checkout row only', function (): void {
    seedNights($this->roomType, $this->checkIn, 3, [
        $this->checkIn->addDays(3)->toDateString() => ['closed_to_departure' => true],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeFalse();

    // The same flag mid-stay is irrelevant — nobody departs mid-stay.
    seedNights($this->roomType, $this->checkIn, 3, [
        $this->checkIn->addDays(3)->toDateString() => ['closed_to_departure' => false],
        $this->checkIn->addDay()->toDateString() => ['closed_to_departure' => true],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeTrue();
});

it('enforces closed_to_arrival on the arrival row only', function (): void {
    seedNights($this->roomType, $this->checkIn, 3, [
        $this->checkIn->toDateString() => ['closed_to_arrival' => true],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeFalse();

    seedNights($this->roomType, $this->checkIn, 3, [
        $this->checkIn->toDateString() => ['closed_to_arrival' => false],
        $this->checkIn->addDay()->toDateString() => ['closed_to_arrival' => true],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeTrue();
});

it('evaluates min_stay on the arrival date, never across the stay', function (): void {
    // Saturday demands 3 nights; Friday demands none. A Fri–Sun two-night
    // stay is exactly what Booking.com would accept — rejecting it because
    // Saturday sits inside the stay silently loses the hotel money (§6).
    seedNights($this->roomType, $this->checkIn, 4, [
        $this->checkIn->addDay()->toDateString() => ['min_stay' => 3],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(2)))->toBeTrue();

    // Arriving ON the min-stay date with too few nights is refused.
    expect($this->service->isBookable($this->roomType, $this->checkIn->addDay(), $this->checkIn->addDays(3)))->toBeFalse();

    // …and accepted with enough.
    expect($this->service->isBookable($this->roomType, $this->checkIn->addDay(), $this->checkIn->addDays(4)))->toBeTrue();
});

it('enforces max_stay and min_stay_through', function (): void {
    seedNights($this->roomType, $this->checkIn, 6, [
        $this->checkIn->toDateString() => ['max_stay' => 2],
    ]);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(3)))->toBeFalse()
        ->and($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(2)))->toBeTrue();

    seedNights($this->roomType, $this->checkIn, 6, [
        $this->checkIn->toDateString() => ['max_stay' => null],
        $this->checkIn->addDay()->toDateString() => ['min_stay_through' => 4],
    ]);

    // A 2-night stay spanning that night is too short…
    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(2)))->toBeFalse()
        // …a 4-night stay through it is fine.
        ->and($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(4)))->toBeTrue();
});

it('treats a missing row as unbookable, never as available', function (): void {
    seedNights($this->roomType, $this->checkIn, 1); // nights row + checkout row for 1 night only

    // Second night's row was never generated → refuse, don't assume (§5).
    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(2)))->toBeFalse();
});

it('rejects an inverted range and an over-long stay', function (): void {
    seedNights($this->roomType, $this->checkIn, 5);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn))->toBeFalse()
        ->and($this->service->isBookable($this->roomType, $this->checkIn->addDay(), $this->checkIn))->toBeFalse();

    config()->set('doba.booking.max_nights', 3);

    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(4)))->toBeFalse();
});

it('checks occupancy against the party when given', function (): void {
    seedNights($this->roomType, $this->checkIn, 2);

    // max_occupancy 3, max_adults 2, max_children 1
    expect($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(2), adults: 2, children: 1))->toBeTrue()
        ->and($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(2), adults: 3))->toBeFalse()
        ->and($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(2), adults: 2, children: 2))->toBeFalse()
        // Two units double every limit — the multi-room path (§6 step 6).
        ->and($this->service->isBookable($this->roomType, $this->checkIn, $this->checkIn->addDays(2), units: 2, adults: 4, children: 2))->toBeTrue();
});

it('does not issue more queries as a hotel adds room types', function (): void {
    $checkIn = CarbonImmutable::today()->addDays(20);
    $checkOut = $checkIn->addDays(5);

    $build = function (int $count) use ($checkIn, $checkOut): int {
        RoomType::query()->delete();

        foreach (range(1, $count) as $i) {
            $roomType = RoomType::create([
                'code' => 'R'.$i, 'base_occupancy' => 2, 'max_occupancy' => 3,
                'default_rate' => 10000, 'total_units' => 1,
            ]);

            $roomType->translations()->create([
                'locale' => 'en', 'slug' => 'room-'.$i, 'name' => 'Room '.$i,
            ]);

            foreach (range(0, 6) as $night) {
                Availability::create([
                    'room_type_id' => $roomType->id,
                    'date' => $checkIn->addDays($night)->toDateString(),
                    'allotment' => 1,
                ]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(AvailabilityService::class)->search($checkIn, $checkOut, 2, 0);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $build(2);
    $large = $build(20);

    // A twenty-room hotel listing its rooms individually once issued over
    // two hundred queries for one search, because every room type asked
    // the same questions and each re-read the same rows. The count must
    // now be flat: a hotel that adds a room must not slow down its own
    // search page.
    expect($large)->toBe($small)
        ->and($large)->toBeLessThan(20);
});

it('prices a large hotel exactly as it prices a small one', function (): void {
    $checkIn = CarbonImmutable::today()->addDays(20);
    $checkOut = $checkIn->addDays(3);

    $roomType = RoomType::create([
        'code' => 'SOLO', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 13500, 'total_units' => 2,
    ]);
    $roomType->translations()->create(['locale' => 'en', 'slug' => 'solo', 'name' => 'Solo']);

    foreach (range(0, 4) as $night) {
        Availability::create([
            'room_type_id' => $roomType->id,
            'date' => $checkIn->addDays($night)->toDateString(),
            'allotment' => 2,
        ]);
    }

    // What one room type costs on its own must be what it costs beside
    // nineteen others — the preloading is an optimisation, not a change
    // of answer.
    $alone = app(AvailabilityService::class)->search($checkIn, $checkOut, 2, 0);

    foreach (range(1, 19) as $i) {
        $other = RoomType::create([
            'code' => 'X'.$i, 'base_occupancy' => 2, 'max_occupancy' => 2,
            'default_rate' => 9000, 'total_units' => 1,
        ]);
        $other->translations()->create(['locale' => 'en', 'slug' => 'x-'.$i, 'name' => 'X '.$i]);

        foreach (range(0, 4) as $night) {
            Availability::create([
                'room_type_id' => $other->id,
                'date' => $checkIn->addDays($night)->toDateString(),
                'allotment' => 1,
            ]);
        }
    }

    $crowded = collect(app(AvailabilityService::class)->search($checkIn, $checkOut, 2, 0))
        ->firstWhere('room_type.code', 'SOLO');

    expect($crowded['total'])->toBe($alone[0]['total'])
        ->and($crowded['per_night'])->toBe($alone[0]['per_night'])
        ->and($crowded['units_left'])->toBe($alone[0]['units_left']);
});
