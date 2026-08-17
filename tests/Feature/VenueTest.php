<?php

declare(strict_types=1);

use App\Enums\Allergen;
use App\Models\Dish;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function venue(array $attributes = [], array $translations = []): Venue
{
    $venue = Venue::create(array_merge([
        'code' => 'RESTAURANT',
        'type' => 'restaurant',
        'seats' => 40,
        'opening_hours' => ['mon' => [['12:00', '14:30'], ['18:00', '22:00']], 'tue' => []],
    ], $attributes));

    foreach ($translations ?: ['en' => ['seehof', 'Restaurant Seehof']] as $locale => [$slug, $name]) {
        $venue->translations()->create([
            'locale' => $locale,
            'slug' => $slug,
            'name' => $name,
            'tagline' => 'Alpine cooking',
        ]);
    }

    return $venue;
}

function dish(Venue $venue, string $name, array $attributes = []): Dish
{
    $section = $venue->sections()->firstOrCreate(['code' => 'MAINS'], ['sort_order' => 0]);
    $section->translations()->firstOrCreate(['locale' => 'en'], ['name' => 'Main courses']);

    $dish = $section->dishes()->create(array_merge(['price' => 3200], $attributes));
    $dish->translations()->create(['locale' => 'en', 'name' => $name, 'description' => 'How it is served.']);

    return $dish;
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
});

it('publishes a venue on its own translated URL and nowhere else', function (): void {
    $venue = venue(translations: [
        'en' => ['seehof', 'Restaurant Seehof'],
        'de' => ['seehof-restaurant', 'Restaurant Seehof'],
    ]);
    dish($venue, 'Veal schnitzel');

    $this->get('/en/dining/seehof')->assertOk()->assertSee('Veal schnitzel');
    $this->get('/de/gastronomie/seehof-restaurant')->assertOk();

    // Slugs never fall back: the English slug is not a German URL (§10).
    $this->get('/de/gastronomie/seehof')->assertNotFound();
});

it('keeps an untranslated venue out of that language entirely', function (): void {
    $venue = venue(translations: ['en' => ['seehof', 'Restaurant Seehof']]);
    dish($venue, 'Veal schnitzel');

    // Listed in English, absent in German — a listing entry with no URL
    // would link to a 404.
    $this->get('/en/dining')->assertOk()->assertSee('Restaurant Seehof');
    $this->get('/de/gastronomie')->assertOk()->assertDontSee('Restaurant Seehof');

    $html = $this->get('/en/dining/seehof')->getContent();

    expect($html)->not->toContain('hreflang="de"');
});

it('prints market price rather than a zero for a dish with no price', function (): void {
    $venue = venue();
    dish($venue, 'The catch of the day', ['price' => null]);

    $html = $this->get('/en/dining/seehof')->assertOk()->getContent();

    expect($html)->toContain(__('menu.market_price'))
        ->not->toContain('€0.00');
});

it('numbers allergens the way a printed card does, ascending and keyed', function (): void {
    $venue = venue();
    // Stored out of order, as a chef ticking boxes would produce.
    dish($venue, 'Veal schnitzel', ['allergens' => ['milk', 'gluten', 'eggs']]);

    // Whitespace-normalised: Blade wraps the line, the guest reads one.
    $html = preg_replace('/\s+/', ' ', $this->get('/en/dining/seehof')->assertOk()->getContent());

    expect($html)->toContain('Contains 1, 3, 7')
        // The key lists only what this card actually uses: printing all
        // fourteen teaches a guest to skip the list.
        ->toContain(__('menu.allergen_gluten'))
        ->not->toContain(__('menu.allergen_lupin'));
});

it('does not present a dish with no allergen data as free of anything', function (): void {
    $unknown = new Dish(['allergens' => null]);
    $declared = new Dish(['allergens' => ['milk']]);

    // An empty list may mean "contains none" or "nobody filled this in",
    // and only one of those is safe to tell a guest with an allergy.
    expect($unknown->isFreeOf(Allergen::Nuts))->toBeFalse()
        ->and($declared->isFreeOf(Allergen::Nuts))->toBeTrue()
        ->and($declared->isFreeOf(Allergen::Milk))->toBeFalse();
});

it('knows a bar is still open after midnight', function (): void {
    $bar = venue([
        'code' => 'BAR',
        'type' => 'bar',
        // Friday 16:00 until 01:00 on Saturday.
        'opening_hours' => ['fri' => [['16:00', '01:00']], 'sat' => [['16:00', '01:00']]],
    ], ['en' => ['bar', 'The Bar']]);

    $friday = CarbonImmutable::parse('2026-09-18 23:30');   // a Friday

    expect($bar->isOpenAt($friday))->toBeTrue()
        // 00:30 on Saturday falls inside Saturday's own wrapped period.
        ->and($bar->isOpenAt($friday->addHour()))->toBeTrue()
        ->and($bar->isOpenAt($friday->setTime(14, 0)))->toBeFalse();
});

it('publishes the whole menu as structured data', function (): void {
    $venue = venue(['type' => 'restaurant', 'price_range' => '€€€', 'reservations' => true]);
    dish($venue, 'Veal schnitzel', ['price' => 3200, 'diets' => ['vegetarian']]);
    dish($venue, 'The catch of the day', ['price' => null]);

    $html = $this->get('/en/dining/seehof')->assertOk()->getContent();

    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    $schemas = array_map(static fn (string $json): array => json_decode($json, true), $matches[1]);
    $restaurant = collect($schemas)->firstWhere('@type', 'Restaurant');

    expect($restaurant)->not->toBeNull()
        ->and($restaurant['priceRange'])->toBe('€€€')
        ->and($restaurant['acceptsReservations'])->toBeTrue();

    $items = $restaurant['hasMenu']['hasMenuSection'][0]['hasMenuItem'];

    expect($items)->toHaveCount(2)
        ->and($items[0]['offers']['price'])->toBe('32.00')
        ->and($items[0]['suitableForDiet'])->toBe(['https://schema.org/VegetarianDiet'])
        // A market-price dish carries no Offer: a structured zero would be
        // a lie a search engine repeats.
        ->and($items[1])->not->toHaveKey('offers');

    // Opening hours are published too, which is what puts "open now" in a
    // search result.
    expect($restaurant['openingHoursSpecification'][0])
        ->dayOfWeek->toBe('https://schema.org/Monday')
        ->opens->toBe('12:00');
});

it('types a bar as a bar and a café as a café', function (): void {
    foreach ([['bar', 'BarOrPub'], ['lounge', 'BarOrPub'], ['cafe', 'CafeOrCoffeeShop']] as $i => [$type, $expected]) {
        $venue = venue(
            ['code' => 'V'.$i, 'type' => $type],
            ['en' => ['v'.$i, ucfirst($type)]],
        );
        dish($venue, 'Negroni '.$i);

        $html = $this->get('/en/dining/v'.$i)->assertOk()->getContent();

        expect($html)->toContain('"@type":"'.$expected.'"');
    }
});

it('lists the venue in the sitemap and the nav, but only where it exists', function (): void {
    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

    // Nothing to link to yet, so nothing is advertised.
    expect($sitemap)->not->toContain('/dining');
    $this->get('/en')->assertOk()->assertDontSee(__('menu.title'));

    $venue = venue();
    dish($venue, 'Veal schnitzel');

    // The endpoint caches for an hour; `doba:sitemap` is what refreshes it
    // in production, so the test does the same rather than asserting on a
    // stale document.
    Cache::forget('doba:sitemap');

    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($sitemap)->toContain('/en/dining')
        ->toContain('/en/dining/seehof');

    $this->get('/en')->assertOk()->assertSee(__('menu.title'));
});

it('hides a venue, a section and a dish that are switched off', function (): void {
    $venue = venue();
    dish($venue, 'Veal schnitzel');
    dish($venue, 'Yesterday special', ['is_available' => false]);

    $this->get('/en/dining/seehof')->assertOk()
        ->assertSee('Veal schnitzel')
        ->assertDontSee('Yesterday special');

    $venue->update(['is_active' => false]);

    $this->get('/en/dining/seehof')->assertNotFound();

    $this->get('/en/dining')->assertOk()->assertDontSee('Restaurant Seehof');
});

it('lets an admin build a card and stores prices in minor units', function (): void {
    $admin = User::factory()->create();
    $venue = venue();

    $this->actingAs($admin)
        ->post("/admin/venues/{$venue->id}/sections", ['code' => 'starters', 'name' => 'Starters'])
        ->assertRedirect();

    $section = $venue->sections()->sole();

    expect($section->code)->toBe('STARTERS');

    $this->actingAs($admin)
        ->post("/admin/venues/{$venue->id}/dishes", [
            'dishes' => [
                'new-1' => [
                    'section' => $section->id,
                    'name' => 'Smoked char',
                    'description' => 'Horseradish cream.',
                    // Entered in euros, as a chef types it.
                    'price' => '18.50',
                    'allergens' => ['fish', 'milk'],
                    'diets' => [],
                    'is_available' => '1',
                ],
                // An empty row is how a chef skips the blank line.
                'new-2' => ['section' => $section->id, 'name' => ''],
            ],
        ])
        ->assertRedirect();

    $dish = Dish::sole();

    expect($dish->price)->toBe(1850)
        ->and($dish->t('name'))->toBe('Smoked char')
        ->and($dish->allergens)->toBe(['fish', 'milk']);
});

it('refuses to move a dish onto another venue section', function (): void {
    $mine = venue();
    $theirs = venue(['code' => 'OTHER'], ['en' => ['other', 'Other place']]);
    $theirSection = $theirs->sections()->create(['code' => 'MAINS']);

    $this->actingAs(User::factory()->create())
        ->post("/admin/venues/{$mine->id}/dishes", [
            'dishes' => [
                'new-1' => ['section' => $theirSection->id, 'name' => 'Injected dish', 'is_available' => '1'],
            ],
        ])
        ->assertRedirect();

    // A section id from another venue is not this form's to write.
    expect(Dish::query()->count())->toBe(0);
});

it('unpublishes a language when its name is cleared', function (): void {
    $venue = venue(translations: [
        'en' => ['seehof', 'Restaurant Seehof'],
        'de' => ['seehof-de', 'Restaurant Seehof'],
    ]);
    dish($venue, 'Veal schnitzel');

    $this->actingAs(User::factory()->create())
        ->put("/admin/venues/{$venue->id}", [
            'code' => 'RESTAURANT',
            'type' => 'restaurant',
            'is_active' => '1',
            'translations' => [
                'en' => ['name' => 'Restaurant Seehof', 'slug' => 'seehof'],
                'de' => ['name' => '', 'slug' => 'seehof-de'],
            ],
        ])
        ->assertRedirect();

    // The URL, the hreflang entry and the sitemap line go together.
    $this->get('/de/gastronomie/seehof-de')->assertNotFound();

    expect($this->get('/sitemap.xml')->getContent())->not->toContain('/de/gastronomie/seehof-de');
});
