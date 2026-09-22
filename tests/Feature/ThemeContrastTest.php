<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Support\Hotel\HotelSettings;
use App\Support\Theme\Contrast;
use App\Support\Theme\StylePreset;

/**
 * Every style preset, and any brand colour a hotelier picks, yields text
 * colours that read at WCAG AA (§3) — the theme derives them, so a gold
 * eyebrow is darkened exactly as far as 4.5:1 needs and no further.
 */
it('derives text-safe colours for every shipped preset', function (): void {
    foreach (array_keys(StylePreset::all()) as $preset) {
        $vars = StylePreset::tokens($preset);
        $derived = StylePreset::derived($vars);
        $get = fn (string $k): string => Contrast::normalise($vars[$k] ?? null) ?? StylePreset::BASE[$k];

        foreach (['--paper', '--paper-2'] as $ground) {
            expect(Contrast::ratio($derived['--doba-accent-text'], $get($ground)))->toBeGreaterThanOrEqual(4.5, "$preset accent text on $ground")
                ->and(Contrast::ratio($derived['--ink-faint'], $get($ground)))->toBeGreaterThanOrEqual(4.5, "$preset faint ink on $ground")
                ->and(Contrast::ratio($derived['--doba-moss'], $get($ground)))->toBeGreaterThanOrEqual(4.5, "$preset calendar price on $ground");
        }

        expect(Contrast::ratio($derived['--doba-accent-btn'], $get('--on-accent')))->toBeGreaterThanOrEqual(4.5, "$preset accent button")
            ->and(Contrast::ratio($derived['--doba-primary-btn'], $get('--on-primary')))->toBeGreaterThanOrEqual(4.5, "$preset primary button");
    }
});

it('leaves a colour alone when it already reads, and copes with a pastel brand colour', function (): void {
    expect(Contrast::ensure('#20362c', '#fbfaf7'))->toBe('#20362c')
        ->and(Contrast::ratio(Contrast::ensure('#f7c6d0', '#ffffff'), '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        // A dark ground is lightened toward white, not darkened into it.
        ->and(Contrast::ratio(Contrast::ensure('#3a3a3a', '#111111'), '#111111'))->toBeGreaterThanOrEqual(4.5)
        ->and(Contrast::normalise('ABC'))->toBe('#aabbcc')
        ->and(Contrast::normalise('not a colour'))->toBeNull();
});

it('emits the derived colours on every page, following the hotel\'s own brand colour', function (): void {
    Setting::put('branding', 'color_accent', '#f2d16b');   // a pale gold nobody could read
    HotelSettings::flush();
    app(HotelSettings::class)->refresh();

    $html = $this->get('/en')->assertOk()->getContent();

    preg_match('/--doba-accent-text:(#[0-9a-f]{6})/', $html, $m);

    expect($m)->not->toBeEmpty()
        ->and(Contrast::ratio($m[1], '#f3f0e9'))->toBeGreaterThanOrEqual(4.5)
        ->and($html)->toContain('--doba-accent:#f2d16b');
});
