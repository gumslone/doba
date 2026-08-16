<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\AppliesPer;
use App\Models\Amenity;
use App\Models\Availability;
use App\Models\Extra;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

function makeExtra(string $code, int $price, string $per, array $overrides = []): Extra
{
    $extra = Extra::create(array_merge([
        'code' => $code,
        'price' => $price,
        'applies_per' => $per,
        'tax_rate' => 1900,
        'max_quantity' => 3,
        'is_active' => true,
    ], $overrides));

    $extra->translations()->create(['locale' => 'en', 'name' => ucfirst(strtolower($code))]);

    return $extra;
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);

    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'default_rate' => 10000,
        'total_units' => 3,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);
});

it('multiplies each pricing basis correctly over a stay', function (): void {
    // 3 nights, 2 guests.
    expect(AppliesPer::Stay->multiplier(3, 2))->toBe(1)
        ->and(AppliesPer::Night->multiplier(3, 2))->toBe(3)
        ->and(AppliesPer::Person->multiplier(3, 2))->toBe(2)
        ->and(AppliesPer::PersonNight->multiplier(3, 2))->toBe(6);

    // Breakfast at €18 per person-night for 3 nights × 2 guests = €108.
    expect(makeExtra('BREAKFAST', 1800, 'person_night')->totalFor(3, 2))->toBe(10800)
        // A €45 transfer is €45 however long the stay.
        ->and(makeExtra('TRANSFER', 4500, 'stay')->totalFor(3, 2))->toBe(4500)
        // Two garage spaces at €12 per night for 3 nights = €72.
        ->and(makeExtra('PARKING', 1200, 'night')->totalFor(3, 2, quantity: 2))->toBe(7200);
});

it('adds extras to a booking with prices snapshotted and totals recomputed', function (): void {
    $checkIn = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 3) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 3,
        ]);
    }

    $breakfast = makeExtra('BREAKFAST', 1800, 'person_night');
    $transfer = makeExtra('TRANSFER', 4500, 'stay');

    $service = app(BookingService::class);

    $booking = $service->place(
        $this->roomType, $checkIn, $checkIn->addDays(3),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2
    );

    expect($booking->subtotal)->toBe(30000);

    $service->addExtras($booking, [$breakfast->id => 1, $transfer->id => 1]);

    // 3 nights × 2 guests × €18 = €108, plus a €45 transfer.
    expect($booking->fresh())
        ->extras_total->toBe(10800 + 4500)
        ->total->toBe(30000 + 10800 + 4500)
        ->balance_due->toBe(45300);

    // The extra's price is frozen on the booking.
    $breakfast->update(['price' => 9900]);

    expect($booking->extras()->where('extra_id', $breakfast->id)->first())
        ->unit_price->toBe(1800)
        ->total->toBe(10800)
        ->applies_per->toBe(AppliesPer::PersonNight)
        ->tax_rate->toBe(1900);
});

it('is idempotent — adding the same extra twice does not double it', function (): void {
    $checkIn = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 2) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 3,
        ]);
    }

    $breakfast = makeExtra('BREAKFAST', 1800, 'person_night');
    $service = app(BookingService::class);

    $booking = $service->place(
        $this->roomType, $checkIn, $checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2
    );

    $service->addExtras($booking, [$breakfast->id => 1]);
    $service->addExtras($booking, [$breakfast->id => 1]);

    expect($booking->extras()->count())->toBe(1)
        ->and($booking->fresh()->extras_total)->toBe(7200); // 2 nights × 2 guests
});

it('caps quantity at max_quantity and never charges an included extra', function (): void {
    $checkIn = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 2) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 3,
        ]);
    }

    $parking = makeExtra('PARKING', 1000, 'night', ['max_quantity' => 2]);
    $pool = makeExtra('POOL', 0, 'stay', ['is_included' => true]);

    $service = app(BookingService::class);

    $booking = $service->place(
        $this->roomType, $checkIn, $checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2
    );

    $service->addExtras($booking, [$parking->id => 99, $pool->id => 1]);

    // Capped at 2 spaces × 2 nights × €10, and the pool is never a line item.
    expect($booking->fresh()->extras_total)->toBe(4000)
        ->and($booking->extras()->where('extra_id', $pool->id)->exists())->toBeFalse();
});

it('offers house-wide extras with every room and scoped ones only where attached', function (): void {
    $breakfast = makeExtra('BREAKFAST', 1800, 'person_night');   // attached to nothing = house-wide
    $cot = makeExtra('COT', 1500, 'night');
    $inactive = makeExtra('OLD', 100, 'stay', ['is_active' => false]);

    $other = RoomType::create([
        'code' => 'SGL', 'base_occupancy' => 1, 'max_occupancy' => 1, 'total_units' => 1,
    ]);

    $cot->roomTypes()->attach($this->roomType);

    $codes = fn (RoomType $rt): array => $rt->availableExtras()->pluck('code')->all();

    expect($codes($this->roomType))->toEqualCanonicalizing(['BREAKFAST', 'COT'])
        ->and($codes($other))->toBe(['BREAKFAST'])
        ->and($codes($this->roomType))->not->toContain('OLD');
});

it('shows extras and grouped inclusions on the room page', function (): void {
    makeExtra('BREAKFAST', 1800, 'person_night');
    makeExtra('POOL', 0, 'stay', ['is_included' => true]);

    foreach ([['wifi', 'room', 'Free WiFi'], ['shower', 'bathroom', 'Walk-in shower']] as [$icon, $category, $name]) {
        $amenity = Amenity::create(['icon' => $icon, 'category' => $category, 'sort_order' => 1]);
        $amenity->translations()->create(['locale' => 'en', 'name' => $name]);
        $amenity->roomTypes()->attach($this->roomType);
    }

    $html = $this->get('/en/rooms/double-room')->assertOk()->getContent();

    expect($html)
        ->toContain(__('extras.includes'))
        ->toContain('Free WiFi')
        ->toContain('Walk-in shower')
        // Grouped under their category headings, not one flat wall of ticks.
        ->toContain(__('extras.category_bathroom'))
        ->toContain(__('extras.category_room'))
        // Extras with the unit they are priced by.
        ->toContain(__('extras.title'))
        ->toContain(__('extras.per_person_night'))
        // The pool is shown as included rather than with a price.
        ->toContain(__('extras.included'));
});

it('groups inclusions in the declared category order', function (): void {
    foreach ([['lake', 'view'], ['wc', 'bathroom'], ['tv', 'room']] as $i => [$icon, $category]) {
        $amenity = Amenity::create(['icon' => $icon, 'category' => $category, 'sort_order' => $i]);
        $amenity->translations()->create(['locale' => 'en', 'name' => ucfirst($icon)]);
        $amenity->roomTypes()->attach($this->roomType);
    }

    // room → bathroom → view, whatever order they were created in.
    expect(array_keys($this->roomType->fresh()->load('amenities.translations')->inclusionsByCategory()))
        ->toBe(['room', 'bathroom', 'view']);
});

it('manages extras from the admin', function (): void {
    $admin = User::factory()->create();

    $this->get('/admin/extras')->assertRedirect('/admin/login');

    $this->actingAs($admin)->post('/admin/extras', [
        'code' => 'spa',
        'price' => '25.00',            // entered in euros…
        'applies_per' => 'person',
        'tax_rate' => 1900,
        'max_quantity' => 4,
        'is_active' => '1',
        'room_type_ids' => [$this->roomType->id],
        'translations' => [
            'en' => ['name' => 'Spa access', 'description' => 'Sauna and pool.'],
            'de' => ['name' => 'Spa-Zugang'],
        ],
    ])->assertRedirect();

    $extra = Extra::sole();

    expect($extra->code)->toBe('SPA')          // upper-cased
        ->and($extra->price)->toBe(2500)       // …stored in cents
        ->and($extra->applies_per)->toBe(AppliesPer::Person)
        ->and($extra->t('name', 'de'))->toBe('Spa-Zugang')
        ->and($extra->roomTypes)->toHaveCount(1);

    $this->get('/en/rooms/double-room')->assertOk()->assertSee('Spa access');
});

it('deactivates rather than deletes an extra that has been sold', function (): void {
    $admin = User::factory()->create();
    $checkIn = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 2) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 3,
        ]);
    }

    $extra = makeExtra('BREAKFAST', 1800, 'person_night');

    $booking = app(BookingService::class)->place(
        $this->roomType, $checkIn, $checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2
    );

    app(BookingService::class)->addExtras($booking, [$extra->id => 1]);

    $this->actingAs($admin)->delete('/admin/extras/'.$extra->id)->assertRedirect();

    // The invoice line still needs its extra, so the row survives.
    expect($extra->fresh())->not->toBeNull()
        ->and($extra->fresh()->is_active)->toBeFalse()
        // …and it stops being offered.
        ->and($this->roomType->availableExtras())->toHaveCount(0);
});

it('rejects a duplicate extra code regardless of case', function (): void {
    $admin = User::factory()->create();

    $payload = [
        'code' => 'spa', 'price' => '25.00', 'applies_per' => 'person',
        'tax_rate' => 1900, 'max_quantity' => 1, 'is_active' => '1',
        'translations' => ['en' => ['name' => 'Spa access']],
    ];

    $this->actingAs($admin)->post('/admin/extras', $payload)->assertRedirect();

    // Codes are stored upper-cased, so "SPA" and "spa" are the same code.
    // Validating the raw input would let this through to the database and
    // return a 500 instead of a field error.
    $this->actingAs($admin)->post('/admin/extras', ['code' => 'SPA'] + $payload)
        ->assertSessionHasErrors('code');

    $this->actingAs($admin)->post('/admin/extras', ['code' => 'spa'] + $payload)
        ->assertSessionHasErrors('code');

    expect(Extra::count())->toBe(1);
});
