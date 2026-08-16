<?php

declare(strict_types=1);

use App\Models\Amenity;
use App\Models\RoomType;
use App\Models\Setting;
use App\Support\Hotel\HotelSettings;

beforeEach(function (): void {
    Setting::put('general', 'name', 'Hotel Alpenhof');
    Setting::put('contact', 'city', 'Rottach-Egern');
    Setting::put('contact', 'country', 'DE');
    Setting::put('contact', 'latitude', '47.6903');
    Setting::put('contact', 'longitude', '11.7639');
    HotelSettings::flush();

    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'size_sqm' => 24,
        'bed_setup' => 'Queen bed',
        'default_rate' => 12500,
        'total_units' => 5,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en',
        'slug' => 'double-room',
        'name' => 'Double room',
        'short_description' => 'A 24 m² double with a balcony.',
    ]);
});

it('publishes a Hotel node with an address and coordinates on the home page', function (): void {
    $blocks = jsonLdBlocks($this->get('/en')->assertOk()->getContent());

    $hotel = collect($blocks)->firstWhere('@type', 'Hotel');

    expect($hotel)->not->toBeNull()
        ->and($hotel['name'])->toBe('Hotel Alpenhof')
        ->and($hotel['address']['@type'])->toBe('PostalAddress')
        ->and($hotel['address']['addressLocality'])->toBe('Rottach-Egern')
        ->and($hotel['geo']['latitude'])->toBe(47.6903);
});

it('publishes localised amenityFeature entries on the room', function (): void {
    $amenity = Amenity::create(['icon' => 'wifi', 'sort_order' => 1]);
    $amenity->translations()->create(['locale' => 'en', 'name' => 'Free WiFi']);
    $amenity->roomTypes()->attach($this->roomType);

    $html = $this->get('/en/rooms/double-room')->assertOk()->getContent();

    $room = collect(jsonLdBlocks($html))->firstWhere('@type', 'HotelRoom');

    expect($room['amenityFeature'][0])->toBe([
        '@type' => 'LocationFeatureSpecification',
        'name' => 'Free WiFi',
        'value' => true,
    ])->and($html)->toContain('Free WiFi'); // visible twin

});

it('publishes a HotelRoom whose Offer price is in major units', function (): void {
    $blocks = jsonLdBlocks($this->get('/en/rooms/double-room')->assertOk()->getContent());

    $room = collect($blocks)->firstWhere('@type', 'HotelRoom');

    expect($room)->not->toBeNull()
        ->and($room['name'])->toBe('Double room')
        // Money is stored as 12500 minor units. Publishing that number raw
        // advertises a €12,500 room — the bug this assertion exists for.
        ->and($room['offers']['price'])->toBe('125.00')
        ->and($room['offers']['priceCurrency'])->toBe('EUR')
        ->and($room['offers']['availability'])->toBe('https://schema.org/InStock')
        ->and($room['containedInPlace']['@id'])->toBe(url('/').'#hotel');
});

it('publishes a breadcrumb trail matching the visible one', function (): void {
    $html = $this->get('/en/rooms/double-room')->assertOk()->getContent();

    $breadcrumbs = collect(jsonLdBlocks($html))->firstWhere('@type', 'BreadcrumbList');

    expect($breadcrumbs['itemListElement'])->toHaveCount(3)
        ->and($breadcrumbs['itemListElement'][2]['name'])->toBe('Double room')
        ->and($breadcrumbs['itemListElement'][2]['position'])->toBe(3);

    expect($html)->toContain('aria-label="Breadcrumb"');
});

it('escapes markup inside content so a description cannot break out of the block', function (): void {
    $this->roomType->translations()->where('locale', 'en')->update([
        'short_description' => 'Cosy</script><script>alert(1)</script>',
    ]);

    $html = $this->get('/en/rooms/double-room')->assertOk()->getContent();

    // The JSON must still parse — which it cannot if the literal </script>
    // survived into the block and closed it early.
    $blocks = jsonLdBlocks($html);

    expect($blocks)->not->toBeEmpty()
        ->and($html)->not->toContain('<script>alert(1)</script>');
});
