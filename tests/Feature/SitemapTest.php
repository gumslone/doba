<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\RoomType;

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);

    $roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'default_rate' => 12500,
        'total_units' => 3,
    ]);

    $roomType->translations()->createMany([
        ['locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room'],
        ['locale' => 'de', 'slug' => 'doppelzimmer', 'name' => 'Doppelzimmer'],
    ]);

    $this->roomType = $roomType;
});

it('lists every translated URL with reciprocal alternates', function (): void {
    $xml = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    $document = new SimpleXMLElement($xml);
    $locations = [];

    foreach ($document->url as $url) {
        $locations[] = (string) $url->loc;
    }

    expect($locations)
        ->toContain(seoHost().'/en/rooms/double-room')
        ->toContain(seoHost().'/de/zimmer/doppelzimmer');
});

it('excludes unpublished and noindexed pages', function (): void {
    Page::create(['code' => 'draft', 'is_published' => false])
        ->translations()->create(['locale' => 'en', 'slug' => 'draft', 'title' => 'Draft']);

    Page::create(['code' => 'thanks', 'is_published' => true, 'noindex' => true])
        ->translations()->create(['locale' => 'en', 'slug' => 'thanks', 'title' => 'Thanks']);

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->not->toContain('/en/draft')
        ->and($xml)->not->toContain('/en/thanks');
});

it('omits a locale a room is not translated into', function (): void {
    $this->roomType->translations()->where('locale', 'de')->delete();

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('/en/rooms/double-room')
        ->and($xml)->not->toContain('/de/zimmer/');
});

it('writes the same document to disk from the artisan command', function (): void {
    $path = storage_path('framework/testing/sitemap.xml');

    $this->artisan('doba:sitemap', ['--path' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toContain('/en/rooms/double-room');

    @unlink($path);
});

it('disallows everything and drops the sitemap line when the install is noindexed', function (): void {
    config()->set('doba.seo.noindex', true);

    $robots = $this->get('/robots.txt')->assertOk()->getContent();

    expect($robots)->toContain('Disallow: /')
        ->and($robots)->not->toContain('Sitemap:');
});

it('keeps crawlers out of the booking funnel in every locale', function (): void {
    $robots = $this->get('/robots.txt')->assertOk()->getContent();

    // Crawling the funnel manufactures holds against real inventory (§6).
    expect($robots)->toContain('Disallow: /en/booking/')
        ->and($robots)->toContain('Disallow: /de/buchung/')
        ->and($robots)->toContain('Sitemap: '.seoHost().'/sitemap.xml');
});
