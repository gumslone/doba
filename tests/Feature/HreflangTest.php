<?php

declare(strict_types=1);

use App\Models\RoomType;

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de', 'fr', 'nl']);

    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'default_rate' => 12500,
        'total_units' => 5,
    ]);

    foreach ([
        'en' => ['double-room', 'Double room'],
        'de' => ['doppelzimmer', 'Doppelzimmer'],
        'fr' => ['chambre-double', 'Chambre double'],
    ] as $locale => [$slug, $name]) {
        $this->roomType->translations()->create([
            'locale' => $locale,
            'slug' => $slug,
            'name' => $name,
        ]);
    }
});

it('emits a reciprocal hreflang set plus x-default', function (): void {
    $response = $this->get('/de/zimmer/doppelzimmer');

    $response->assertOk()
        ->assertSee('<link rel="alternate" hreflang="de" href="'.seoHost().'/de/zimmer/doppelzimmer">', false)
        ->assertSee('<link rel="alternate" hreflang="en" href="'.seoHost().'/en/rooms/double-room">', false)
        ->assertSee('<link rel="alternate" hreflang="fr" href="'.seoHost().'/fr/chambres/chambre-double">', false)
        ->assertSee('<link rel="alternate" hreflang="x-default" href="'.seoHost().'/en/rooms/double-room">', false);
});

it('omits an alternate for a locale the room is not translated into', function (): void {
    // The Dutch translation was never created. An alternate pointing at a
    // fallback-rendered Dutch page would tell Google two URLs are
    // translations of each other when one of them does not exist.
    $this->get('/de/zimmer/doppelzimmer')
        ->assertOk()
        ->assertDontSee('hreflang="nl"', false);
});

it('404s an untranslated slug rather than serving it under a second locale', function (): void {
    $this->get('/nl/kamers/doppelzimmer')->assertNotFound();
    $this->get('/en/rooms/doppelzimmer')->assertNotFound();
});

it('resolves each locale to its own translated path segment and slug', function (string $path, string $expectedTitle): void {
    $this->get($path)->assertOk()->assertSee($expectedTitle, false);
})->with([
    ['/en/rooms/double-room', 'Double room'],
    ['/de/zimmer/doppelzimmer', 'Doppelzimmer'],
    ['/fr/chambres/chambre-double', 'Chambre double'],
]);

it('points the language switcher at the current page, not the home page', function (): void {
    $this->get('/de/zimmer/doppelzimmer')
        ->assertOk()
        ->assertSee('href="'.seoHost().'/fr/chambres/chambre-double"', false);
});
