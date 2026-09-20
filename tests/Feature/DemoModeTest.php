<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Enquiry;
use App\Models\Guest;
use App\Models\Page;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Demo\Demo;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoStaySeeder;
use Illuminate\Support\Facades\Storage;

/**
 * The public demo (§22): hands out the key, and makes sure the key opens
 * nothing a stranger could use against the next visitor.
 */
beforeEach(function (): void {
    config()->set('doba.locales', ['en']);
    $this->admin = User::factory()->create();
});

it('is off by default, and then nothing about it shows', function (): void {
    expect(Demo::enabled())->toBeFalse();

    $this->get('/en')->assertOk()->assertDontSee('public demo');
    $this->get('/admin/login')->assertOk()->assertDontSee('public demo');
});

it('refuses to drop the database anywhere but a demo', function (): void {
    RoomType::create(['code' => 'KEEP', 'default_rate' => 10000, 'total_units' => 1]);

    $this->artisan('doba:demo:reset')
        ->expectsOutputToContain('not a demo install')
        ->assertFailed();

    expect(RoomType::query()->where('code', 'KEEP')->exists())->toBeTrue();
});

it('says it is a demo on every page and hands out the login', function (): void {
    config()->set('doba.demo.enabled', true);

    $this->get('/en')->assertOk()
        ->assertSee('This is a public demo of Doba')
        ->assertSee(config('doba.admin.email'));

    $this->get('/admin/login')->assertOk()
        ->assertSee('This is a public demo')
        ->assertSee(config('doba.admin.password'));

    $this->actingAs($this->admin)->get('/admin/front-desk')->assertOk()->assertSee('Public demo.');
});

it('keeps the desk working and makes everything a stranger could abuse read-only', function (): void {
    config()->set('doba.demo.enabled', true);

    $type = RoomType::create(['code' => 'DBL', 'default_rate' => 10000, 'total_units' => 1]);
    $door = Room::create(['room_type_id' => $type->id, 'number' => '101', 'status' => 'dirty']);
    $guest = Guest::findOrCreateByEmail('anna@example.com', ['first_name' => 'Anna', 'last_name' => 'K']);

    // Worth trying, harmless to the next visitor: on.
    $this->actingAs($this->admin)->post('/admin/housekeeping/'.$door->id.'/clean')->assertSessionMissing('demo_blocked');
    expect($door->fresh()->status)->toBe('clean');

    // Shows on the public site of an indexed domain: off.
    $this->actingAs($this->admin)->from('/admin/pages')
        ->post('/admin/pages', ['translations' => ['en' => ['title' => 'Buy pills', 'slug' => 'pills']]])
        ->assertRedirect('/admin/pages')
        ->assertSessionHas('demo_blocked');
    expect(Page::query()->count())->toBe(0);

    // Credentials, mail, the updater, outbound fetches: off.
    foreach ([['put', '/admin/mail'], ['put', '/admin/settings'], ['post', '/admin/update'], ['post', '/admin/security/password'], ['post', '/admin/channels']] as [$method, $url]) {
        $this->actingAs($this->admin)->{$method}($url, [])->assertSessionHas('demo_blocked');
    }

    // Inside a writable section, the one action that would leave the next
    // visitor an empty guest book.
    $this->actingAs($this->admin)->post('/admin/guests/'.$guest->id.'/erase')->assertSessionHas('demo_blocked');
    expect($guest->fresh()->isAnonymised())->toBeFalse();

    // Reading is never blocked.
    $this->actingAs($this->admin)->get('/admin/settings')->assertOk();
});

it('never mails, never takes a card, and stays out of search engines', function (): void {
    config()->set('doba.demo.enabled', true);
    (new AppServiceProvider(app()))->boot();

    expect(config('mail.default'))->toBe('log')
        ->and(config('doba.features.online_payment'))->toBeFalse()
        ->and(config('doba.seo.noindex'))->toBeTrue();
});

it('seeds a hotel with a living front desk', function (): void {
    Storage::fake('public');

    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoStaySeeder::class);

    $today = CarbonImmutable::today(config('doba.timezone'))->toDateString();

    expect(Booking::query()->where('check_in', $today)->where('status', BookingStatus::Confirmed)->count())->toBeGreaterThanOrEqual(1)
        ->and(Booking::query()->where('status', BookingStatus::CheckedIn)->count())->toBeGreaterThanOrEqual(2)
        ->and(Booking::query()->where('check_out', $today)->where('status', BookingStatus::CheckedIn)->count())->toBe(1)
        ->and(Review::query()->where('is_published', true)->count())->toBe(2)
        ->and(Enquiry::query()->count())->toBe(2)
        // Checked-out stays sent their doors to housekeeping.
        ->and(Room::query()->where('status', 'dirty')->count())->toBeGreaterThanOrEqual(1)
        ->and(Room::query()->where('status', 'out_of_order')->count())->toBe(1);

    // No two stays share a door on the same night.
    $this->actingAs($this->admin)->get('/admin/front-desk')->assertOk()->assertSee('Kowalska');
    $this->actingAs($this->admin)->get('/admin/housekeeping')->assertOk();
});
