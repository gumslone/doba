<?php

declare(strict_types=1);

use App\Models\Availability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'default_rate' => 12500,
        'total_units' => 2,
    ]);

    $this->from = CarbonImmutable::today()->addDays(5);

    foreach (range(0, 4) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->from->addDays($i)->toDateString(),
            'allotment' => 2,
            'booked' => $i === 1 ? 2 : 0,          // day 1 sold out
            'closed' => $i === 2,                  // day 2 stop-sell
            'min_stay' => $i === 3 ? 3 : 1,
            'closed_to_arrival' => $i === 3,
            'price' => $i === 4 ? 9900 : null,
        ]);
    }
});

it('returns the per-date payload the picker renders', function (): void {
    $response = $this->getJson(
        '/api/calendar?room_type='.$this->roomType->id
        .'&from='.$this->from->toDateString()
        .'&to='.$this->from->addDays(4)->toDateString()
    )->assertOk()->assertHeader('Cache-Control', 'max-age=60, public');

    $days = $response->json('days');

    expect($days)->toHaveCount(5)
        ->and($days[0])->toBe([
            'date' => $this->from->toDateString(),
            'available' => true,
            'price' => 12500,
            'min_stay' => 1,
            'cta' => false,
            'ctd' => false,
        ])
        ->and($days[1]['available'])->toBeFalse()   // sold out
        ->and($days[2]['available'])->toBeFalse()   // stop-sell
        ->and($days[3]['min_stay'])->toBe(3)
        ->and($days[3]['cta'])->toBeTrue()
        ->and($days[4]['price'])->toBe(9900);       // override beats default
});

it('marks dates beyond the generated horizon as unavailable', function (): void {
    $days = $this->getJson(
        '/api/calendar?room_type='.$this->roomType->id
        .'&from='.$this->from->addDays(4)->toDateString()
        .'&to='.$this->from->addDays(6)->toDateString()
    )->assertOk()->json('days');

    // Rows exist only through day 4 — the two dates past the horizon are
    // unsellable, never "assumed available".
    expect($days[0]['available'])->toBeTrue()
        ->and($days[1]['available'])->toBeFalse()
        ->and($days[2]['available'])->toBeFalse();
});

it('validates its parameters', function (): void {
    $this->getJson('/api/calendar')->assertStatus(422);

    $this->getJson('/api/calendar?room_type='.$this->roomType->id.'&from=2027-06-05&to=2027-06-01')
        ->assertStatus(422);

    $this->getJson('/api/calendar?room_type=999&from=2027-06-01&to=2027-06-05')
        ->assertStatus(422);
});

it('caps a scraper-sized range instead of erroring', function (): void {
    $days = $this->getJson(
        '/api/calendar?room_type='.$this->roomType->id
        .'&from='.$this->from->toDateString()
        .'&to='.$this->from->addDays(400)->toDateString()
    )->assertOk()->json('days');

    expect(count($days))->toBe(93); // 92 days + the inclusive start
});

it('404s an inactive room type without leaking its calendar', function (): void {
    $this->roomType->update(['is_active' => false]);

    $this->getJson(
        '/api/calendar?room_type='.$this->roomType->id
        .'&from='.$this->from->toDateString()
        .'&to='.$this->from->addDays(2)->toDateString()
    )->assertStatus(404);
});
