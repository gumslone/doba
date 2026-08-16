<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Setting;
use App\Support\Hotel\HotelSettings;

function makeEvent(array $attributes = [], array $translations = []): Event
{
    $event = Event::create(array_merge([
        'starts_at' => now()->addDays(10)->setTime(18, 0),
        'is_published' => true,
    ], $attributes));

    foreach ($translations ?: [
        'en' => ['slug' => 'wine-tasting', 'title' => 'Wine tasting'],
        'de' => ['slug' => 'weinverkostung', 'title' => 'Weinverkostung'],
    ] as $locale => $translation) {
        $event->translations()->create($translation + ['locale' => $locale]);
    }

    return $event;
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
});

it('lists upcoming events on the home page and the events page', function (): void {
    makeEvent();

    $this->get('/en')->assertOk()->assertSee('Wine tasting');
    $this->get('/en/events')->assertOk()->assertSee('Wine tasting');
    $this->get('/de/veranstaltungen')->assertOk()->assertSee('Weinverkostung');
});

it('hides unpublished and past events', function (): void {
    makeEvent(['is_published' => false], ['en' => ['slug' => 'draft-event', 'title' => 'Draft event']]);
    makeEvent(['starts_at' => now()->subDays(5)], ['en' => ['slug' => 'past-event', 'title' => 'Past event']]);

    $html = $this->get('/en/events')->assertOk()->getContent();

    expect($html)->not->toContain('Draft event')
        ->and($html)->not->toContain('Past event');

    $this->get('/en/events/draft-event')->assertNotFound();
});

it('keeps a multi-day event listed until it ends', function (): void {
    makeEvent([
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ], ['en' => ['slug' => 'festival', 'title' => 'Festival weekend']]);

    $this->get('/en/events')->assertOk()->assertSee('Festival weekend');
});

it('publishes valid Event JSON-LD with the hotel as the default venue', function (): void {
    Setting::put('general', 'name', 'Hotel Alpenhof');
    Setting::put('contact', 'city', 'Rottach-Egern');
    HotelSettings::flush();

    $event = makeEvent();

    $html = $this->get('/en/events/wine-tasting')->assertOk()->getContent();

    $schema = collect(jsonLdBlocks($html))->firstWhere('@type', 'Event');

    expect($schema)->not->toBeNull()
        ->and($schema['name'])->toBe('Wine tasting')
        ->and($schema['startDate'])->toBe($event->starts_at->toIso8601String())
        ->and($schema['location']['@type'])->toBe('Place')
        ->and($schema['location']['name'])->toBe('Hotel Alpenhof')
        ->and($schema['location']['address']['addressLocality'])->toBe('Rottach-Egern')
        ->and($schema['organizer']['name'])->toBe('Hotel Alpenhof');
});

it('uses a named location over the hotel when set', function (): void {
    makeEvent(['location' => 'Lakeside terrace']);

    $schema = collect(jsonLdBlocks($this->get('/en/events/wine-tasting')->assertOk()->getContent()))
        ->firstWhere('@type', 'Event');

    expect($schema['location']['name'])->toBe('Lakeside terrace');
});

it('emits hreflang only for translated locales and 404s the rest', function (): void {
    makeEvent([], ['en' => ['slug' => 'english-only', 'title' => 'English only']]);

    $html = $this->get('/en/events/english-only')->assertOk()->getContent();

    expect($html)->toContain('hreflang="en"')
        ->and($html)->not->toContain('hreflang="de"');

    $this->get('/de/veranstaltungen/english-only')->assertNotFound();
});

it('lists translated event URLs in the sitemap', function (): void {
    makeEvent();

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain(seoHost().'/en/events/wine-tasting')
        ->and($xml)->toContain(seoHost().'/de/veranstaltungen/weinverkostung');
});
