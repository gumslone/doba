<?php

declare(strict_types=1);

use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Directory\PropertyDescriptor;
use App\Support\Hotel\HotelSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Being findable, without being taken over (§21).
 */
beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 12500, 'total_units' => 2,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);

    $this->checkIn = CarbonImmutable::today()->addDays(20);

    foreach (range(0, 6) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->checkIn->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    $this->listed = function (): void {
        Setting::put('directory', 'enabled', true);
        app(HotelSettings::class)->refresh();
    };
});

it('is invisible until the hotel opts in', function (): void {
    // 404, not 403: an install that has not opted in should look like one
    // that was never built, not like one hiding something.
    $this->getJson('/.well-known/doba.json')->assertNotFound();
    $this->getJson('/api/directory/quote?check_in='.$this->checkIn->toDateString().'&check_out='.$this->checkIn->addDay()->toDateString())
        ->assertNotFound();

    ($this->listed)();

    $this->getJson('/.well-known/doba.json')->assertOk();
});

it('describes the property without saying anything a brochure would not', function (): void {
    ($this->listed)();

    Setting::put('contact', 'city', 'Innsbruck');
    Setting::put('contact', 'latitude', 47.2692);
    Setting::put('contact', 'longitude', 11.4041);
    app(HotelSettings::class)->refresh();

    $response = $this->getJson('/.well-known/doba.json')->assertOk();

    expect($response->json('doba_directory_version'))->toBe(PropertyDescriptor::VERSION)
        ->and($response->json('property.address.city'))->toBe('Innsbruck')
        ->and($response->json('property.geo.latitude'))->toBe(47.2692)
        ->and($response->json('room_types.0.code'))->toBe('DBL')
        ->and($response->json('room_types.0.from_rate'))->toBe(['amount' => 12500, 'currency' => 'EUR'])
        // Where a guest goes, and where a machine goes.
        ->and($response->json('endpoints.quote'))->toContain('/api/directory/quote');

    // Nothing about the business itself. A hotelier should be able to
    // read this in full and find nothing they would not have printed in a
    // brochure — so the shape is asserted exactly, and a field added
    // later has to be argued for here first.
    expect(array_keys($response->json()))->toBe([
        'doba_directory_version', 'install_id', 'software', 'url', 'updated_at',
        'property', 'room_types', 'amenities', 'endpoints', 'capabilities',
    ]);

    $body = strtolower((string) $response->getContent());

    // `base_occupancy` is a room's capacity and belongs in a brochure.
    // These are the hotel's own numbers, and none of them belong here.
    foreach (['revenue', 'revpar', '"adr"', '"held"', '"booked"', '"reference"', '"guest"'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('keeps a stable identity when the hotel moves domain', function (): void {
    ($this->listed)();

    $first = PropertyDescriptor::installId();

    config(['app.url' => 'https://alpenrose.tirol']);
    app(HotelSettings::class)->refresh();

    // The same business, so the same listing — not a second one, and not
    // a history thrown away during the changeover.
    expect(PropertyDescriptor::installId())->toBe($first);
});

it('answers a conditional GET with 304, so a hub can poll cheaply', function (): void {
    ($this->listed)();

    $etag = $this->getJson('/.well-known/doba.json')->assertOk()->headers->get('ETag');

    expect($etag)->toBeString();

    $this->withHeaders(['If-None-Match' => (string) $etag])
        ->getJson('/.well-known/doba.json')
        ->assertStatus(304);
});

it('quotes real dates through the same search the website runs', function (): void {
    ($this->listed)();

    $response = $this->getJson('/api/directory/quote?check_in='.$this->checkIn->toDateString()
        .'&check_out='.$this->checkIn->addDays(2)->toDateString().'&adults=2&ref=tyrol')->assertOk();

    expect($response->json('bookable'))->toBeTrue()
        ->and($response->json('offers.0.room_type'))->toBe('DBL')
        ->and($response->json('offers.0.total'))->toBe(['amount' => 25000, 'currency' => 'EUR'])
        // The aggregator never takes the booking: it hands the guest back
        // with the dates they searched for already filled in.
        ->and($response->json('offers.0.booking_url'))->toContain('check_in='.$this->checkIn->toDateString())
        ->and($response->json('offers.0.booking_url'))->toContain('ref=tyrol');
});

it('separates "we do not take those dates" from "we are full"', function (): void {
    ($this->listed)();

    // Beyond the booking window: not a sold-out hotel, and a hub that
    // cannot tell them apart shows a hotel as full when it is not.
    $far = CarbonImmutable::today()->addDays((int) config('doba.booking.booking_window_days') + 30);

    $response = $this->getJson('/api/directory/quote?check_in='.$far->toDateString()
        .'&check_out='.$far->addDay()->toDateString().'&adults=2')->assertOk();

    expect($response->json('bookable'))->toBeFalse()
        ->and($response->json('reason'))->not->toBeNull();
});

it('bounds a stay a scraper would ask for', function (): void {
    ($this->listed)();

    $this->getJson('/api/directory/quote?check_in='.$this->checkIn->toDateString()
        .'&check_out='.$this->checkIn->addDays(400)->toDateString().'&adults=2')
        ->assertStatus(422)
        ->assertJsonPath('error', 'stay_too_long');
});

it('credits the directory that sent a guest, so the listing can be judged', function (): void {
    config()->set('doba.payment.gateway', 'manual');
    ($this->listed)();

    // Arrives from the aggregator, wanders the site, and books later from
    // a page that has long since lost the query string.
    $this->get('/en/rooms?ref=tyrol')->assertOk();
    $this->get('/en/booking/search?'.http_build_query([
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'children' => 0,
    ]))->assertOk();

    $this->post('/en/booking', [
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'adults' => 2,
        'children' => 0,
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna',
        'last_name' => 'Kowalska',
        'email' => 'anna@example.com',
        'terms' => '1',
    ]);

    // Without this the hotel cannot answer the only question that decides
    // whether a listing is worth keeping: did it bring anybody?
    expect(Booking::sole()->source)->toBe('ref:tyrol');
});

it('announces itself to the hub, and remembers whether that worked', function (): void {
    ($this->listed)();

    config(['app.url' => 'https://alpenrose.example']);
    Setting::put('directory', 'hub', 'https://directory.example');
    app(HotelSettings::class)->refresh();

    Http::fake(['directory.example/*' => Http::response(['ok' => true])]);

    $this->artisan('doba:directory:announce')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request['url'] === 'https://alpenrose.example'
        && $request['descriptor'] === 'https://alpenrose.example/.well-known/doba.json');

    app(HotelSettings::class)->refresh();

    expect(app(HotelSettings::class)->get('directory.last_announce')['ok'])->toBeTrue();
});

it('says so rather than failing silently when the hub cannot be reached', function (): void {
    ($this->listed)();

    config(['app.url' => 'https://alpenrose.example']);
    Setting::put('directory', 'hub', 'https://directory.example');
    app(HotelSettings::class)->refresh();

    Http::fake(['directory.example/*' => Http::response('nope', 500)]);

    $this->artisan('doba:directory:announce')->assertFailed();

    app(HotelSettings::class)->refresh();

    // A silent nightly job is one nobody notices has been failing since
    // March.
    expect(app(HotelSettings::class)->get('directory.last_announce')['ok'])->toBeFalse();
});

it('will not announce a hotel a hub could not verify', function (): void {
    ($this->listed)();

    config(['app.url' => 'http://localhost:8000']);

    // The hub proves a listing by fetching the descriptor from the domain
    // that claims it, over HTTPS. Sending this would fail there instead,
    // a week later, with nobody watching.
    $this->artisan('doba:directory:announce')->assertFailed();
});

it('keeps the decision to be listed behind the admin session', function (): void {
    $this->get('/admin/directory')->assertRedirect('/admin/login');
    $this->post('/admin/directory', ['hub' => 'https://directory.example'])->assertRedirect('/admin/login');
});

it('lets a hotelier read the document before agreeing to publish it', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin/directory')->assertOk();

    // Consent to a document you cannot see is not consent.
    $response->assertSee('doba_directory_version')->assertSee('DBL');

    $this->actingAs($user)->post('/admin/directory', [
        'enabled' => '1',
        'hub' => 'https://directory.example',
    ])->assertRedirect('/admin/directory');

    app(HotelSettings::class)->refresh();

    expect(PropertyDescriptor::isEnabled())->toBeTrue()
        ->and(PropertyDescriptor::hub())->toBe('https://directory.example');
});
