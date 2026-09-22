<?php

declare(strict_types=1);

use App\Models\Availability;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Rates for the Google Business Profile: per night, the lowest price a
 * guest could actually book on the website, as a final price.
 */
beforeEach(function (): void {
    config()->set('doba.locales', ['en']);
    config()->set('doba.taxes.city_tax_per_person_night', 250);
    $this->today = CarbonImmutable::today(config('doba.timezone'));

    $make = function (string $code, int $rate, array $extra = []): RoomType {
        $type = RoomType::create($extra + ['code' => $code, 'base_occupancy' => 2, 'max_occupancy' => 2, 'default_rate' => $rate, 'total_units' => 1]);
        $type->translations()->create(['locale' => 'en', 'slug' => strtolower($code), 'name' => $code.' room']);

        foreach (range(0, 5) as $i) {
            Availability::create(['room_type_id' => $type->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString(), 'allotment' => 1]);
        }

        return $type;
    };

    $this->cheap = $make('CHEAP', 8000);
    $this->dear = $make('DEAR', 15000);
    // Cheaper still, but never let for one night: not a one-night price.
    $make('FLAT', 5000, ['kind' => 'apartment', 'min_nights' => 3]);
    // A single cannot host the two guests Google quotes for.
    $make('SINGLE', 4000, ['max_occupancy' => 1, 'base_occupancy' => 1]);
});

it('is for staff', function (): void {
    $this->get('/admin/google-rates')->assertRedirect('/admin/login');
    $this->get('/admin/google-rates/export')->assertRedirect('/admin/login');
});

it('quotes the lowest bookable one-night price, visitor\'s tax included', function (): void {
    $rows = array_map('str_getcsv', explode("\n", trim($this->actingAs(User::factory()->create())->get('/admin/google-rates/export')->assertOk()->streamedContent())));
    $today = collect($rows)->first(fn (array $r): bool => $r[0] === $this->today->toDateString());

    // 80.00 room + 2 guests × 2.50 tax. Not the flat, not the single.
    expect($today[2])->toBe('yes')
        ->and($today[3])->toBe('85.00')
        ->and($today[4])->toBe('80.00')
        ->and($today[5])->toBe('CHEAP room')
        ->and(count($rows))->toBe(91);
});

it('moves to the next room when the cheapest is sold, and says so when nothing is left', function (): void {
    Availability::query()->where('room_type_id', $this->cheap->id)->where('date', $this->today->toDateString())->update(['booked' => 1]);
    Availability::query()->where('date', $this->today->addDay()->toDateString())->update(['closed' => true]);

    $page = $this->actingAs(User::factory()->create())->get('/admin/google-rates')->assertOk();

    $page->assertSee('DEAR room')->assertSee('nothing bookable for one night')
        // The link to paste into the Business Profile.
        ->assertSee('/en/booking/search');
});
