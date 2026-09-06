<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\HotelSettings;

/**
 * The hotel's own details, editable at last (§12).
 */
function settingsPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'general' => ['name' => 'Hotel Bergblick', 'star_rating' => 4, 'since' => 1962],
        'contact' => [
            'email' => 'desk@bergblick.example', 'phone' => '+43 5 000',
            'street' => 'Dorfstraße 1', 'postal_code' => '6100', 'city' => 'Seefeld', 'country' => 'AT',
            'latitude' => '47.33', 'longitude' => '11.19',
        ],
        'translations' => [
            'general.tagline' => ['en' => 'Calm above the valley', 'de' => 'Ruhe über dem Tal'],
            'seo.title' => ['en' => 'Bergblick — Seefeld'],
            'seo.description' => ['en' => 'A small house above the valley.'],
            'policy.cancellation' => ['en' => 'Free until 48 h before arrival.'],
        ],
        'usps' => [
            ['icon' => 'spa', 'title' => 'Alpine spa', 'subtitle' => 'Mountain view'],
            ['icon' => 'x', 'title' => '', 'subtitle' => 'dropped because untitled'],
        ],
        'social' => ['instagram' => 'https://instagram.com/bergblick'],
        'tax' => ['vat_id' => 'ATU12345678', 'accommodation_rate' => 1000],
        'analytics' => ['id' => ''],
        'env' => ['timezone' => 'Europe/Vienna', 'currency' => 'eur', 'checkin_from' => '14:00', 'checkout_until' => '10:30'],
    ], $overrides);
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    $this->admin = User::factory()->create();
});

it('keeps the settings behind the admin session', function (): void {
    $this->get('/admin/settings')->assertRedirect('/admin/login');
    $this->post('/admin/settings', settingsPayload())->assertRedirect('/admin/login');
});

it('saves plain, per-language and runtime settings in one go', function (): void {
    $this->actingAs($this->admin)->post('/admin/settings', settingsPayload())
        ->assertRedirect('/admin/settings')->assertSessionHas('saved');

    $hotel = app(HotelSettings::class);
    $hotel->refresh();

    // Plain values.
    expect($hotel->name)->toBe('Hotel Bergblick')
        ->and($hotel->get('contact.city'))->toBe('Seefeld')
        ->and($hotel->hasCoordinates())->toBeTrue()
        ->and($hotel->get('tax.accommodation_rate'))->toBe(1000);

    // Per-language, resolved by the active locale — the same maps the
    // wizard and the seeder write, so nothing downstream changes.
    app()->setLocale('de');
    expect($hotel->get('general.tagline'))->toBe('Ruhe über dem Tal');
    app()->setLocale('en');
    expect($hotel->get('general.tagline'))->toBe('Calm above the valley')
        ->and($hotel->get('seo.title'))->toBe('Bergblick — Seefeld');

    // USPs: the untitled row dropped, the icon kept.
    expect($hotel->get('general.usps'))->toBe([['icon' => 'spa', 'title' => 'Alpine spa', 'subtitle' => 'Mountain view']]);

    // The four runtime values landed in the (isolated) .env and in the
    // running config, upper-cased where currencies are.
    $env = (string) file_get_contents(config('doba.install.env_path'));

    expect($env)->toContain('APP_TIMEZONE=Europe/Vienna')
        ->toContain('DOBA_CURRENCY=EUR')
        ->toContain('DOBA_CHECKIN_FROM=14:00')
        ->and(config('doba.checkout_until'))->toBe('10:30');
});

it('never erases a language the form did not show', function (): void {
    // Written while the hotel served French too.
    Setting::put('general', 'tagline', ['en' => 'Old', 'de' => 'Alt', 'fr' => 'Vieux'], true);

    $this->actingAs($this->admin)->post('/admin/settings', settingsPayload([
        'translations' => ['general.tagline' => ['en' => 'New', 'de' => 'Neu']],
    ]));

    $stored = Setting::query()->where('group', 'general')->where('key', 'tagline')->sole()->value;

    // French was not on the form, so French is untouched — switching the
    // site's languages must not be the thing that deletes a text.
    // (Order-insensitive: MySQL's JSON column reorders object keys.)
    expect($stored)->toEqualCanonicalizing(['en' => 'New', 'de' => 'Neu', 'fr' => 'Vieux']);
});

it('refuses coordinates and times that cannot be right', function (): void {
    $this->actingAs($this->admin)->post('/admin/settings', settingsPayload([
        'contact' => ['latitude' => '120'],
    ]))->assertSessionHasErrors('contact.latitude');

    $this->actingAs($this->admin)->post('/admin/settings', settingsPayload([
        'env' => ['checkin_from' => '3pm'],
    ]))->assertSessionHasErrors('env.checkin_from');

    $this->actingAs($this->admin)->post('/admin/settings', settingsPayload([
        'env' => ['timezone' => 'Mars/Olympus'],
    ]))->assertSessionHasErrors('env.timezone');
});

it('shows what is stored, per language', function (): void {
    Setting::put('general', 'name', 'Hotel Bergblick');
    Setting::put('general', 'tagline', ['en' => 'Calm above the valley', 'de' => 'Ruhe über dem Tal'], true);
    Setting::put('contact', 'city', 'Seefeld');

    $this->actingAs($this->admin)->get('/admin/settings')
        ->assertOk()
        ->assertSee('Hotel Bergblick')
        ->assertSee('Calm above the valley')
        ->assertSee('Ruhe über dem Tal')
        ->assertSee('Seefeld');
});
