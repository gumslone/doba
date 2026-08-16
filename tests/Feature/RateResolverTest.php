<?php

declare(strict_types=1);

use App\Domain\Availability\AvailabilityService;
use App\Domain\Pricing\RateResolver;
use App\Models\Availability;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'default_rate' => 10000,
        'total_units' => 5,
    ]);

    // A fixed Monday, so weekday-mask expectations are stable.
    $this->monday = CarbonImmutable::parse('next monday')->addWeeks(2);
});

it('resolves default rate when nothing else matches', function (): void {
    expect(app(RateResolver::class)->nightlyPrice($this->roomType, $this->monday))->toBe(10000);
});

it('prefers a season rate whose weekday mask matches', function (): void {
    $season = Season::create([
        'name' => 'Summer',
        'starts_on' => $this->monday->subDays(30)->toDateString(),
        'ends_on' => $this->monday->addDays(30)->toDateString(),
        'priority' => 1,
    ]);

    // Weekends only.
    $season->rates()->create([
        'room_type_id' => $this->roomType->id,
        'weekday_mask' => SeasonRate::SATURDAY | SeasonRate::SUNDAY,
        'price' => 14500,
    ]);

    $resolver = app(RateResolver::class);
    $saturday = $this->monday->addDays(5);

    expect($resolver->nightlyPrice($this->roomType, $this->monday))->toBe(10000)
        ->and($resolver->nightlyPrice($this->roomType, $saturday))->toBe(14500);
});

it('prefers the higher-priority season when two overlap', function (): void {
    foreach ([['Base', 1, 12000], ['Fair week', 10, 18000]] as [$name, $priority, $price]) {
        Season::create([
            'name' => $name,
            'starts_on' => $this->monday->subDays(5)->toDateString(),
            'ends_on' => $this->monday->addDays(5)->toDateString(),
            'priority' => $priority,
        ])->rates()->create([
            'room_type_id' => $this->roomType->id,
            'weekday_mask' => SeasonRate::ALL_WEEK,
            'price' => $price,
        ]);
    }

    expect(app(RateResolver::class)->nightlyPrice($this->roomType, $this->monday))->toBe(18000);
});

it('lets an availability override beat every season', function (): void {
    Season::create([
        'name' => 'Summer',
        'starts_on' => $this->monday->subDays(5)->toDateString(),
        'ends_on' => $this->monday->addDays(5)->toDateString(),
        'priority' => 1,
    ])->rates()->create([
        'room_type_id' => $this->roomType->id,
        'weekday_mask' => SeasonRate::ALL_WEEK,
        'price' => 14500,
    ]);

    $row = Availability::create([
        'room_type_id' => $this->roomType->id,
        'date' => $this->monday->toDateString(),
        'allotment' => 5,
        'price' => 9900,
    ]);

    expect(app(RateResolver::class)->nightlyPrice($this->roomType, $this->monday, $row))->toBe(9900);
});

it('sums resolved nightly prices into a stay price', function (): void {
    // 2 nights at default + 1 overridden night.
    foreach (range(0, 3) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->monday->addDays($i)->toDateString(),
            'allotment' => 5,
            'price' => $i === 1 ? 8000 : null,
        ]);
    }

    $total = app(AvailabilityService::class)->stayPrice(
        $this->roomType,
        $this->monday,
        $this->monday->addDays(3)
    );

    expect($total)->toBe(10000 + 8000 + 10000);
});

it('returns null when a night has no resolvable price', function (): void {
    $this->roomType->update(['default_rate' => null]);

    Availability::create([
        'room_type_id' => $this->roomType->id,
        'date' => $this->monday->toDateString(),
        'allotment' => 5,
    ]);

    expect(app(AvailabilityService::class)->stayPrice($this->roomType, $this->monday, $this->monday->addDay()))
        ->toBeNull();
});
