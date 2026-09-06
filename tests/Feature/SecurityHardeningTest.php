<?php

declare(strict_types=1);

use App\Domain\Guests\GuestPrivacy;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Page;
use App\Models\Review;
use App\Models\User;
use App\Support\Html;
use App\Support\Maintenance\HealthCheck;

/**
 * The hardening pass (§14): stored HTML is sanitised twice, the CSP no
 * longer grants inline script, erasure takes a guest's reviews with
 * them, and a debug flag in production fails the health check.
 */
it('strips script, handlers and javascript: URLs from stored HTML, and keeps the rest', function (): void {
    $dirty = '<p onclick="steal()">Welcome <strong>home</strong></p>'
        .'<script>alert(1)</script>'
        .'<a href="javascript:alert(1)">click</a>'
        .'<a href="https://example.com" rel="noopener">fine</a>'
        .'<img src="data:text/html;base64,PHNjcmlwdD4=" alt="x">'
        .'<img src="/storage/photo.jpg" alt="room">';

    $clean = (string) Html::clean($dirty);

    expect($clean)->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('data:')
        // The content itself survives: this is an allow-list, not a
        // strip-everything.
        ->toContain('<strong>home</strong>')
        ->toContain('href="https://example.com"')
        ->toContain('src="/storage/photo.jpg"');
});

it('sanitises on write in the admin and again on render', function (): void {
    config()->set('doba.locales', ['en']);
    $admin = User::factory()->create();

    // Write side: the page body is cleaned before it is stored.
    $this->actingAs($admin)->post('/admin/pages', [
        'code' => 'about',
        'is_published' => '1',
        'translations' => ['en' => [
            'title' => 'About',
            'slug' => 'about',
            'body' => '<p>Hello</p><script>alert(1)</script>',
        ]],
    ]);

    $page = Page::query()->where('code', 'about')->sole();

    expect((string) $page->translations()->first()?->body)
        ->toContain('<p>Hello</p>')
        ->not->toContain('<script');

    // Render side: content that predates the sanitiser — planted straight
    // into the database — still comes out clean.
    $page->translations()->updateOrCreate(['locale' => 'en'], [
        'title' => 'About', 'slug' => 'about',
        'body' => '<p>Planted</p><script>alert(2)</script><p onmouseover="x()">hover</p>',
    ]);

    // The page legitimately carries a module tag and JSON-LD, so the
    // assertion is about the planted script, not the word.
    $this->get('/en/about')->assertOk()
        ->assertSee('Planted')
        ->assertDontSee('alert(2)', false)
        ->assertDontSee('onmouseover', false);
});

it('no longer grants inline script in the Content-Security-Policy', function (): void {
    config()->set('doba.locales', ['en']);

    $csp = (string) $this->get('/en')->headers->get('Content-Security-Policy');

    preg_match('/script-src ([^;]+)/', $csp, $m);

    // Every behaviour that used to be an onclick/onchange/onsubmit lives
    // in behaviours.js now, which is what makes this line possible — and
    // what makes an injected <script> in a page body do nothing.
    expect($m[1] ?? '')->toContain("'self'")->not->toContain('unsafe-inline');
});

it('takes an erased guest\'s reviews with them', function (): void {
    $guest = Guest::create(['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K']);

    $booking = Booking::create([
        'reference' => 'T-1', 'manage_token' => str_repeat('t', 40), 'status' => 'checked_out',
        'check_in' => now()->subDays(5)->toDateString(), 'check_out' => now()->subDays(3)->toDateString(),
        'nights' => 2, 'adults' => 2, 'children' => 0, 'currency' => 'EUR',
        'subtotal' => 0, 'total' => 0, 'deposit_due' => 0, 'balance_due' => 0, 'locale' => 'en',
        'guest_id' => $guest->id,
    ]);

    Review::create(['booking_id' => $booking->id, 'guest_id' => $guest->id, 'rating' => 5, 'body' => str_repeat('great ', 5), 'locale' => 'en'])
        ->forceFill(['is_published' => true, 'published_at' => now()])->save();

    app(GuestPrivacy::class)->erase($guest);

    // Their words about us are personal data twice over: theirs, and
    // signed with their name. Published under "Guest" is the erasure not
    // having happened.
    expect(Review::query()->count())->toBe(0);
});

it('fails the health check when debug is on in production', function (): void {
    config(['app.debug' => true, 'app.env' => 'production']);

    $failures = HealthCheck::failures(app(HealthCheck::class)->all(deep: false));

    expect(collect($failures)->pluck('key')->all())->toContain('debug');

    // Off, or on outside production, is fine.
    config(['app.debug' => true, 'app.env' => 'local']);
    expect(collect(HealthCheck::failures(app(HealthCheck::class)->all(deep: false)))->pluck('key')->all())->not->toContain('debug');
});
