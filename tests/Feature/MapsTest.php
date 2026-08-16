<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Support\Hotel\HotelSettings;

beforeEach(function (): void {
    Setting::put('general', 'name', 'Hotel Alpenhof');
    Setting::put('contact', 'latitude', '47.6903');
    Setting::put('contact', 'longitude', '11.7639');
    HotelSettings::flush();
});

it('offers the map click-to-load rather than calling Google on page load', function (): void {
    $html = $this->get('/en/contact')->assertOk()->getContent();

    // The button and the privacy note are present…
    expect($html)->toContain(__('contact.map_load'))
        ->toContain(__('contact.map_privacy'))
        // …and the embed URL travels as a data attribute that a click
        // turns into an iframe, so the served markup contains no frame at
        // all and nothing is fetched from Google until the visitor asks.
        ->toContain('data-frame-container')
        ->toContain('data-load-frame');

    expect(substr_count($html, '<iframe'))->toBe(0);
});

it('links out to Google Maps with the hotel coordinates', function (): void {
    $this->get('/en/contact')
        ->assertOk()
        ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=47.6903%2C11.7639', false);
});

it('publishes hasMap in the Hotel structured data', function (): void {
    $hotel = collect(jsonLdBlocks($this->get('/en')->assertOk()->getContent()))
        ->firstWhere('@type', 'Hotel');

    expect($hotel['hasMap'])->toBe('https://www.google.com/maps/search/?api=1&query=47.6903%2C11.7639')
        ->and($hotel['geo']['latitude'])->toBe(47.6903);
});

it('omits the map entirely when the hotel has no coordinates', function (): void {
    Setting::query()->where('group', 'contact')->whereIn('key', ['latitude', 'longitude'])->delete();
    HotelSettings::flush();

    $html = $this->get('/en/contact')->assertOk()->getContent();

    expect($html)->not->toContain('<iframe')
        ->and($html)->not->toContain(__('contact.map_load'));

    $hotel = collect(jsonLdBlocks($this->get('/en')->assertOk()->getContent()))
        ->firstWhere('@type', 'Hotel');

    expect($hotel)->not->toHaveKey('hasMap');
});

it('permits the map frame in the CSP without opening the page to third parties', function (): void {
    $csp = $this->get('/en/contact')->assertOk()->headers->get('Content-Security-Policy');

    expect($csp)->toContain('frame-src https://maps.google.com')
        // Scripts and styles stay first-party — the map is the single
        // deliberate exception, and only for a frame.
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->not->toContain('script-src https://');
});
