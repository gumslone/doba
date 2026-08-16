<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\Redirect;
use App\Models\RoomType;

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
});

it('emits a self-referencing canonical on every public page', function (): void {
    $roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2, 'total_units' => 1,
    ]);
    $roomType->translations()->create(['locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room']);

    $this->get('/en/rooms/double-room')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.seoHost().'/en/rooms/double-room">', false);
});

it('ignores query strings in the canonical', function (): void {
    // ?utm_source=… must not fork the page into a second indexable URL.
    $this->get('/en/rooms?utm_source=newsletter&page=2')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.seoHost().'/en/rooms">', false);
});

it('content-negotiates the bare domain with a 302, not a 301', function (): void {
    // A 301 would be cached by the first visitor's browser and then serve
    // that visitor's language to them forever.
    $this->get('/', ['Accept-Language' => 'de-DE,de;q=0.9'])
        ->assertRedirect(seoHost().'/de')
        ->assertStatus(302);

    $this->get('/', ['Accept-Language' => 'en-GB,en;q=0.9'])
        ->assertRedirect(seoHost().'/en');
});

it('noindexes a page flagged as such without hiding it', function (): void {
    Page::create(['code' => 'thanks', 'is_published' => true, 'noindex' => true])
        ->translations()->create(['locale' => 'en', 'slug' => 'thanks', 'title' => 'Thank you']);

    $this->get('/en/thanks')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('noindexes the whole install when DOBA_NOINDEX is on', function (): void {
    config()->set('doba.seo.noindex', true);

    $this->get('/en')
        ->assertOk()
        ->assertSee('content="noindex, nofollow"', false);
});

it('follows a legacy-URL redirect and carries the query string across', function (): void {
    Redirect::create(['from' => '/spa', 'to' => '/en/rooms', 'code' => 301]);

    $this->get('/spa?utm_source=oldsite')
        ->assertStatus(301)
        ->assertRedirect(seoHost().'/en/rooms?utm_source=oldsite');

    expect(Redirect::first()->hits)->toBe(1);
});

it('normalises a trailing slash to the same redirect row', function (): void {
    Redirect::create(['from' => '/wellness', 'to' => '/en/rooms']);

    $this->get('/wellness/')->assertStatus(301);
});

it('still 404s a path with no redirect row', function (): void {
    $this->get('/en/does-not-exist')->assertNotFound();
});

it('truncates an over-long title on a word boundary', function (): void {
    $roomType = RoomType::create([
        'code' => 'SUITE', 'base_occupancy' => 2, 'max_occupancy' => 4, 'total_units' => 1,
    ]);
    $roomType->translations()->create([
        'locale' => 'en',
        'slug' => 'panoramic-suite',
        'name' => 'Panoramic mountain view suite with private balcony and separate lounge',
    ]);

    $html = $this->get('/en/rooms/panoramic-suite')->assertOk()->getContent();

    preg_match('#<title>(.*?)</title>#s', $html, $matches);

    expect(mb_strlen($matches[1]))->toBeLessThanOrEqual(60)
        ->and($matches[1])->toEndWith('…');
});
