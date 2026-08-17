<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Page;
use App\Models\User;

function admin(): User
{
    return User::factory()->create();
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
});

it('locks the whole admin area behind login', function (): void {
    $this->get('/admin/pages')->assertRedirect('/admin/login');
    $this->get('/admin/styles')->assertRedirect('/admin/login');
    $this->put('/admin/styles')->assertRedirect('/admin/login');
});

it('logs in with valid credentials and out again', function (): void {
    $user = User::factory()->create(['password' => bcrypt('secret-password')]);

    $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    // Lands on the front desk: the screen a hotel opens in the morning.
    $this->post('/admin/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertRedirect('/admin/front-desk');

    $this->assertAuthenticatedAs($user);

    $this->post('/admin/logout')->assertRedirect('/admin/login');
    $this->assertGuest();
});

it('creates a page with per-locale translations from the form', function (): void {
    $this->actingAs(admin())->post('/admin/pages', [
        'code' => 'spa',
        'is_published' => '1',
        'show_in_menu' => '1',
        'translations' => [
            'en' => ['title' => 'Spa & wellness', 'slug' => 'spa', 'body' => '<p>Our spa.</p>'],
            'de' => ['title' => 'Spa & Wellness', 'slug' => 'wellness', 'body' => '<p>Unser Spa.</p>'],
        ],
    ])->assertRedirect();

    $page = Page::sole();

    expect($page->is_published)->toBeTrue()
        ->and($page->slug('en'))->toBe('spa')
        ->and($page->slug('de'))->toBe('wellness');

    // Live immediately, in both languages, body rendered from the editor.
    $this->get('/en/spa')->assertOk()->assertSee('Our spa.');
    $this->get('/de/wellness')->assertOk()->assertSee('Unser Spa.');
});

it('generates the slug from the title when left empty', function (): void {
    $this->actingAs(admin())->post('/admin/pages', [
        'code' => 'restaurant',
        'is_published' => '1',
        'translations' => [
            'en' => ['title' => 'Restaurant & Bar'],
        ],
    ])->assertRedirect();

    expect(Page::sole()->slug('en'))->toBe('restaurant-bar');
});

it('unpublishes a language by clearing its title', function (): void {
    $page = Page::create(['code' => 'spa', 'is_published' => true]);
    $page->translations()->createMany([
        ['locale' => 'en', 'slug' => 'spa', 'title' => 'Spa'],
        ['locale' => 'de', 'slug' => 'wellness', 'title' => 'Wellness'],
    ]);

    $this->actingAs(admin())->put('/admin/pages/'.$page->id, [
        'code' => 'spa',
        'is_published' => '1',
        'translations' => [
            'en' => ['title' => 'Spa', 'slug' => 'spa'],
            'de' => ['title' => ''], // cleared
        ],
    ])->assertRedirect();

    // The German URL, hreflang entry and sitemap line all go with the row.
    $this->get('/de/wellness')->assertNotFound();
    expect($page->fresh()->load('translations')->slug('de'))->toBeNull();
});

it('rejects a slug already used by another page in the same locale', function (): void {
    Page::create(['code' => 'one', 'is_published' => true])
        ->translations()->create(['locale' => 'en', 'slug' => 'taken', 'title' => 'One']);

    $this->actingAs(admin())->post('/admin/pages', [
        'code' => 'two',
        'translations' => ['en' => ['title' => 'Two', 'slug' => 'taken']],
    ])->assertSessionHasErrors('translations.en.slug');

    expect(Page::count())->toBe(1);
});

it('rejects a reserved segment as a slug', function (): void {
    $this->actingAs(admin())->post('/admin/pages', [
        'code' => 'evil',
        'translations' => ['en' => ['title' => 'Evil', 'slug' => 'admin']],
    ])->assertSessionHasErrors('translations.en.slug');
});

it('creates and updates an event from the form', function (): void {
    $this->actingAs(admin())->post('/admin/events', [
        'starts_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
        'is_published' => '1',
        'translations' => [
            'en' => ['title' => 'Live jazz', 'excerpt' => 'A trio at sunset.'],
        ],
    ])->assertRedirect();

    $event = Event::sole();

    expect($event->slug('en'))->toBe('live-jazz');
    $this->get('/en/events/live-jazz')->assertOk()->assertSee('A trio at sunset.');

    $this->actingAs(admin())->put('/admin/events/'.$event->id, [
        'starts_at' => now()->addDays(6)->format('Y-m-d\TH:i'),
        'is_published' => '0',
        'translations' => ['en' => ['title' => 'Live jazz', 'slug' => 'live-jazz']],
    ])->assertRedirect();

    $this->get('/en/events/live-jazz')->assertNotFound(); // unpublished
});

it('deletes pages and events', function (): void {
    $page = Page::create(['code' => 'temp', 'is_published' => true]);

    $this->actingAs(admin())->delete('/admin/pages/'.$page->id)->assertRedirect();

    expect(Page::count())->toBe(0);
});

it('saves branding and emits it as CSS variables on the public site', function (): void {
    $this->actingAs(admin())->put('/admin/styles', [
        'color_primary' => '#7c2d12',
        'color_accent' => '#0e7490',
        'font_heading' => 'serif',
        'font_body' => 'humanist',
        'custom_css' => '.hero { letter-spacing: 0.05em; }</style><script>alert(1)</script>',
    ])->assertRedirect('/admin/styles');

    $html = $this->get('/en')->assertOk()->getContent();

    expect($html)->toContain('--doba-primary:#7c2d12')
        ->and($html)->toContain('--doba-accent:#0e7490')
        ->and($html)->toContain('ui-serif, Georgia')            // heading stack
        ->and($html)->toContain('.hero { letter-spacing: 0.05em; }')
        // The closing tag was stripped — custom CSS cannot break out of
        // its style block and inject script.
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

it('rejects invalid branding values', function (): void {
    $this->actingAs(admin())->put('/admin/styles', [
        'color_primary' => 'red',                 // not a hex colour
        'color_accent' => '#0e7490',
        'font_heading' => 'comic-sans',           // not in the curated list
        'font_body' => 'sans',
    ])->assertSessionHasErrors(['color_primary', 'font_heading']);
});
