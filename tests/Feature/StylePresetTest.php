<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\HotelSettings;
use App\Support\Theme\StylePreset;

it('emits a preset as CSS variables on the public site', function (): void {
    Setting::put('branding', 'preset', 'kontor');

    $html = $this->get('/en')->assertOk()->getContent();

    expect($html)->toContain('--doba-primary:#0d0d0d')
        ->toContain('--btn-radius:0px')
        // Shape and rhythm, not just colour: a preset that could only
        // repaint would be a palette, not a look.
        ->toContain('--h1-weight:700')
        ->toContain('--section-pad:76px');
});

it('costs nothing when the house look is chosen', function (): void {
    Setting::put('branding', 'preset', StylePreset::DEFAULT);

    // The default's values are already compiled into app.css, so there is
    // nothing to override and no inline block to send.
    expect(StylePreset::tokens(StylePreset::DEFAULT))->toBe([]);
});

it('lets the hotelier own colour out-rank the preset it started from', function (): void {
    Setting::put('branding', 'preset', 'marisol');
    Setting::put('branding', 'color_primary', '#7b1fa2');

    $html = $this->get('/en')->assertOk()->getContent();

    // Their purple wins over Marisol's teal, but Marisol's shape survives.
    expect($html)->toContain('--doba-primary:#7b1fa2')
        ->not->toContain('--doba-primary:#1d6f7a');

    expect($html)->toContain('--btn-radius:100px');
});

it('keeps rendering when a settings row outlives its preset', function (): void {
    Setting::put('branding', 'preset', 'a-preset-removed-in-a-later-release');

    // Falling back beats 500-ing every page on the site.
    expect(StylePreset::tokens('a-preset-removed-in-a-later-release'))->toBe([]);

    $this->get('/en')->assertOk();
});

it('keeps the current look when a save says nothing about the preset', function (): void {
    Setting::put('branding', 'preset', 'kalyna');

    $this->actingAs(User::factory()->create())
        ->put('/admin/styles', [
            'color_primary' => '#112233',
            'color_accent' => '#445566',
            'font_heading' => 'serif',
            'font_body' => 'sans',
        ])
        ->assertRedirect('/admin/styles');

    expect(app(HotelSettings::class)->get('branding.preset'))->toBe('kalyna');
});

it('refuses a preset the admin form did not offer', function (): void {
    $this->actingAs(User::factory()->create())
        ->put('/admin/styles', [
            'preset' => '"><script>alert(1)</script>',
            'color_primary' => '#112233',
            'color_accent' => '#445566',
            'font_heading' => 'serif',
            'font_body' => 'sans',
        ])
        ->assertSessionHasErrors('preset');
});

it('offers every preset in the admin with a readable description', function (): void {
    $response = $this->actingAs(User::factory()->create())->get('/admin/styles')->assertOk();

    foreach (StylePreset::all() as $id => $preset) {
        $response->assertSee('value="'.$id.'"', false)
            ->assertSee($preset['label'])
            // A real sentence, not the translation key leaking through:
            // __() returns the key unchanged when a string is missing, so
            // asserting on __() alone would pass on an untranslated preset.
            ->assertSee(__($preset['description']));

        expect(__($preset['description']))->not->toBe($preset['description']);
    }
});

it('never lets a preset token carry a character that could close the style block', function (): void {
    foreach (StylePreset::all() as $preset) {
        foreach ($preset['tokens'] as $name => $value) {
            expect($name)->toMatch('/^--[a-z0-9-]+$/')
                ->and($value)->not->toContain('<')
                ->and($value)->not->toContain('}');
        }
    }
});

it('inverts the page cleanly for the dark preset', function (): void {
    Setting::put('branding', 'preset', 'grand');

    $html = $this->get('/en')->assertOk()->getContent();

    // A dark preset is only possible because the surfaces are a token: a
    // hard-coded #fff card on a near-black page is the bug this catches.
    expect($html)->toContain('--surface:#1d1a15')
        ->toContain('--paper:#14120f')
        ->toContain('--ink:#f2ece1')
        // Dark type on the gold button, not white — the one control every
        // guest is meant to press must stay legible.
        ->toContain('--on-primary:#14120f');
});

it('leaves no surface hard-coded white in the stylesheet', function (): void {
    $css = file_get_contents(base_path('resources/css/app.css'));

    // Anything that paints a panel must go through --surface, or the dark
    // preset regresses the next time a component is added.
    expect($css)->not->toMatch('/background:\s*#fff\s*[;}]/');
});

it('never paints a brand-coloured band with a fixed text colour', function (): void {
    $css = file_get_contents(base_path('resources/css/app.css'));

    // The top bar and footer sit on the brand colour. A preset that makes
    // that colour light turns hard-coded pale text into gold-on-gold, so
    // both bands must derive their type from the brand's own contrast
    // colour rather than assuming the brand is dark.
    preg_match_all('/(?:footer\.site|\.topbar|\.fbottom|\.lang)[^{]*\{[^}]*\}/', $css, $rules);

    foreach ($rules[0] as $rule) {
        expect($rule)->not->toMatch('/color:\s*(#[0-9a-f]{3,6}|rgba?\()/i');
    }
});

it('floats the footer free of the brand where the brand is too light for it', function (): void {
    Setting::put('branding', 'preset', 'grand');

    $html = $this->get('/en')->assertOk()->getContent();

    // Gold-on-gold is what a footer painted with a light brand gives you.
    expect($html)->toContain('--footer-bg:#0d0b09')
        ->toContain('--footer-on:#f2ece1');
});

it('never lets a component class collide with a Tailwind utility', function (): void {
    $css = file_get_contents(base_path('resources/css/app.css'));

    preg_match_all('/^\.([a-z][a-z0-9-]*)\s*[,{]/m', $css, $matches);

    // Tailwind's own utility names. A component that owns one of these
    // applies its styling to every element that used the utility — which
    // is how `.block { margin-top: 44px }` put 44px above every
    // `class="block text-sm"` form label on the contact page.
    $utilities = [
        'block', 'inline', 'flex', 'grid', 'hidden', 'table', 'contents',
        'container', 'static', 'fixed', 'absolute', 'relative', 'sticky',
        'border', 'rounded', 'shadow', 'transition', 'truncate', 'sr-only',
        'italic', 'underline', 'uppercase', 'visible', 'invisible',
    ];

    $collisions = array_values(array_intersect(array_unique($matches[1]), $utilities));

    expect($collisions)->toBe([]);
});
