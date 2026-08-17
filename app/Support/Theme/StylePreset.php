<?php

declare(strict_types=1);

namespace App\Support\Theme;

/**
 * Named bundles of design tokens (§3).
 *
 * A preset is DATA, not a theme directory. Every preset renders the exact
 * same markup — what changes is the token values the CSS already consumes,
 * so a hotelier switching from the coastal look to the urban one restyles
 * the whole site without forking a single Blade file, and every later
 * upstream fix reaches them.
 *
 * That is the whole reason these are not four copies of the theme: the
 * moment a look becomes a directory of views, it stops receiving the
 * accessibility fix, the schema.org addition and the security patch.
 *
 * The hotelier's own colour and font choices are emitted AFTER these, so
 * picking a preset is a starting point and never a cage.
 */
final class StylePreset
{
    public const DEFAULT = 'alpenhof';

    /**
     * @return array<string,array{label:string,description:string,swatch:array<int,string>,tokens:array<string,string>}>
     */
    public static function all(): array
    {
        return [
            // The house look: alpine, warm paper, gold accent, near-square
            // corners. Matches the tokens compiled into app.css, so it
            // emits nothing and costs nothing.
            'alpenhof' => [
                'label' => 'Alpenhof',
                'description' => 'style.alpenhof_description',
                'swatch' => ['#20362c', '#a8823f', '#fbfaf7'],
                'tokens' => [],
            ],

            // Urban, monochrome, editorial. Zero radius, hairline grid
            // gaps, heavy tight display type, no shadows at all.
            'kontor' => [
                'label' => 'Kontor',
                'description' => 'style.kontor_description',
                'swatch' => ['#0d0d0d', '#c2410c', '#ffffff'],
                'tokens' => [
                    '--ink' => '#0d0d0d',
                    '--ink-soft' => '#3d3d3d',
                    '--ink-faint' => '#8a8a8a',
                    '--paper' => '#ffffff',
                    '--paper-2' => '#f2f2f0',
                    '--line' => '#dcdcda',
                    '--doba-primary' => '#0d0d0d',
                    '--doba-accent' => '#c2410c',
                    '--radius' => '0px',
                    '--radius-lg' => '0px',
                    '--btn-radius' => '0px',
                    '--btn-transform' => 'uppercase',
                    '--btn-tracking' => '.14em',
                    '--doba-font-heading' => '"Helvetica Neue", Helvetica, Arial, sans-serif',
                    '--doba-font-body' => '"Helvetica Neue", Helvetica, Arial, sans-serif',
                    '--h1-size' => 'clamp(2.6rem, 6.4vw, 5.4rem)',
                    '--h1-weight' => '700',
                    '--h1-tracking' => '-.045em',
                    '--h1-leading' => '.94',
                    '--eyebrow-size' => '.66rem',
                    '--eyebrow-tracking' => '.24em',
                    '--section-pad' => '76px',
                    '--grid-gap' => '1px',
                    '--shadow' => 'none',
                    '--shadow-lg' => 'none',
                ],
            ],

            // Coastal, soft and generous. Deep radii, pill buttons, warm
            // sand paper, teal brand with a terracotta accent.
            'marisol' => [
                'label' => 'Marisol',
                'description' => 'style.marisol_description',
                'swatch' => ['#1d6f7a', '#d0674a', '#fdf8f2'],
                'tokens' => [
                    '--ink' => '#2a2320',
                    '--ink-soft' => '#6b5f57',
                    '--ink-faint' => '#a0938a',
                    '--paper' => '#fdf8f2',
                    '--paper-2' => '#f7ece0',
                    '--line' => '#ecdfd0',
                    '--doba-primary' => '#1d6f7a',
                    '--doba-accent' => '#d0674a',
                    '--radius' => '16px',
                    '--radius-lg' => '28px',
                    '--btn-radius' => '100px',
                    '--h1-size' => 'clamp(2.6rem, 5.6vw, 4.4rem)',
                    '--h1-tracking' => '-.02em',
                    '--h1-leading' => '1.06',
                    '--eyebrow-tracking' => '.2em',
                    '--section-pad' => '96px',
                    '--grid-gap' => '22px',
                    '--shadow' => '0 2px 8px rgba(80, 55, 40, .05), 0 16px 40px rgba(80, 55, 40, .08)',
                    '--shadow-lg' => '0 4px 12px rgba(80, 55, 40, .06), 0 32px 70px rgba(80, 55, 40, .14)',
                ],
            ],

            // Spa and sanatorium: calm greens, restrained type, medium
            // radii — the look a wellness property with packages wants.
            'kalyna' => [
                'label' => 'Kalyna',
                'description' => 'style.kalyna_description',
                'swatch' => ['#3d6b52', '#9d2b3f', '#f6f8f5'],
                'tokens' => [
                    '--ink' => '#1f2a26',
                    '--ink-soft' => '#4e5c56',
                    '--ink-faint' => '#889691',
                    '--paper' => '#f6f8f5',
                    '--paper-2' => '#e9efe8',
                    '--line' => '#d8e2d7',
                    '--doba-primary' => '#3d6b52',
                    '--doba-accent' => '#9d2b3f',
                    '--radius' => '10px',
                    '--radius-lg' => '14px',
                    '--btn-radius' => '8px',
                    '--h1-size' => 'clamp(2.2rem, 4.4vw, 3.5rem)',
                    '--h1-leading' => '1.16',
                    '--eyebrow-tracking' => '.16em',
                    '--section-pad' => '84px',
                    '--grid-gap' => '20px',
                    '--shadow' => '0 1px 3px rgba(31, 42, 38, .05), 0 10px 28px rgba(31, 42, 38, .07)',
                    '--shadow-lg' => '0 3px 8px rgba(31, 42, 38, .07), 0 26px 60px rgba(31, 42, 38, .12)',
                ],
            ],

            // Dark, gilded, old-world grand hotel. The one preset that
            // inverts the page, which is why --surface and --on-primary
            // exist as tokens at all.
            'grand' => [
                'label' => 'Grand',
                'description' => 'style.grand_description',
                'swatch' => ['#c9a227', '#14120f', '#1d1a15'],
                'tokens' => [
                    '--ink' => '#f2ece1',
                    '--ink-soft' => '#c3b8a6',
                    '--ink-faint' => '#8d8371',
                    '--paper' => '#14120f',
                    '--paper-2' => '#1d1a15',
                    '--line' => '#332e26',
                    '--surface' => '#1d1a15',
                    '--doba-primary' => '#c9a227',
                    '--doba-accent' => '#c9a227',
                    // Dark type on gold: white on this would fail contrast
                    // on the one button every guest is meant to press.
                    '--on-primary' => '#14120f',
                    '--on-accent' => '#14120f',
                    // The footer floats free of the brand here: gold-on-gold
                    // is what a footer painted with a light brand colour
                    // gives you, and it is unreadable.
                    '--footer-bg' => '#0d0b09',
                    '--footer-on' => '#f2ece1',
                    '--radius' => '0px',
                    '--radius-lg' => '0px',
                    '--btn-radius' => '0px',
                    '--btn-transform' => 'uppercase',
                    '--btn-tracking' => '.2em',
                    '--h1-size' => 'clamp(2.5rem, 5.2vw, 4.6rem)',
                    '--h1-tracking' => '.005em',
                    '--h1-leading' => '1.1',
                    '--eyebrow-size' => '.64rem',
                    '--eyebrow-tracking' => '.34em',
                    '--section-pad' => '104px',
                    '--grid-gap' => '0px',
                    '--shadow' => 'none',
                    '--shadow-lg' => '0 30px 70px rgba(0, 0, 0, .5)',
                ],
            ],

            // Hostel: loud, flat, hard offset shadows, per-bed pricing.
            'nest' => [
                'label' => 'Nest',
                'description' => 'style.nest_description',
                'swatch' => ['#161616', '#e5342a', '#ffe94d'],
                'tokens' => [
                    '--ink' => '#161616',
                    '--ink-soft' => '#484848',
                    '--ink-faint' => '#8b8b8b',
                    '--paper' => '#fffdf6',
                    '--paper-2' => '#ffe94d',
                    '--line' => '#161616',
                    '--doba-primary' => '#161616',
                    '--doba-accent' => '#e5342a',
                    '--radius' => '14px',
                    '--radius-lg' => '22px',
                    '--btn-radius' => '100px',
                    '--doba-font-heading' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif',
                    '--h1-size' => 'clamp(2.7rem, 6.6vw, 5.2rem)',
                    '--h1-weight' => '800',
                    '--h1-tracking' => '-.035em',
                    '--h1-leading' => '.96',
                    '--eyebrow-tracking' => '.14em',
                    '--section-pad' => '72px',
                    '--grid-gap' => '16px',
                    // Hard offset shadows in the line colour: the flat,
                    // printed look, with no blur anywhere.
                    '--shadow' => '4px 4px 0 var(--line)',
                    '--shadow-lg' => '8px 8px 0 var(--line)',
                ],
            ],

            // Ryokan: air, hairlines, wide-tracked display type set large
            // on the line rather than large on the page.
            'yamabuki' => [
                'label' => 'Yamabuki',
                'description' => 'style.yamabuki_description',
                'swatch' => ['#3f4a44', '#8c6d3f', '#f7f4ee'],
                'tokens' => [
                    '--ink' => '#26221d',
                    '--ink-soft' => '#5d564c',
                    '--ink-faint' => '#9a9084',
                    '--paper' => '#f7f4ee',
                    '--paper-2' => '#efeade',
                    '--line' => '#ded7c8',
                    '--surface' => '#fdfcf8',
                    '--doba-primary' => '#3f4a44',
                    '--doba-accent' => '#8c6d3f',
                    '--radius' => '0px',
                    '--radius-lg' => '0px',
                    '--btn-radius' => '0px',
                    '--btn-tracking' => '.14em',
                    '--doba-font-heading' => '"Iowan Old Style", Georgia, "Hiragino Mincho ProN", serif',
                    '--h1-size' => 'clamp(1.7rem, 2.9vw, 2.6rem)',
                    '--h1-tracking' => '.08em',
                    '--h1-leading' => '1.7',
                    '--eyebrow-size' => '.6rem',
                    '--eyebrow-tracking' => '.4em',
                    '--section-pad' => '124px',
                    '--grid-gap' => '0px',
                    '--shadow' => 'none',
                    '--shadow-lg' => 'none',
                ],
            ],

            // Aparthotel: cool, businesslike, built for long stays and a
            // rate table rather than a hero photograph.
            'residence' => [
                'label' => 'Residence',
                'description' => 'style.residence_description',
                'swatch' => ['#12496b', '#0f8a7e', '#f7f9fb'],
                'tokens' => [
                    '--ink' => '#14202b',
                    '--ink-soft' => '#42546a',
                    '--ink-faint' => '#8496a8',
                    '--paper' => '#f7f9fb',
                    '--paper-2' => '#eaf0f5',
                    '--line' => '#d5e0e9',
                    '--doba-primary' => '#12496b',
                    '--doba-accent' => '#0f8a7e',
                    '--radius' => '6px',
                    '--radius-lg' => '8px',
                    '--btn-radius' => '6px',
                    '--doba-font-heading' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif',
                    '--h1-size' => 'clamp(2.1rem, 4vw, 3.2rem)',
                    '--h1-weight' => '700',
                    '--h1-tracking' => '-.025em',
                    '--h1-leading' => '1.08',
                    '--eyebrow-size' => '.68rem',
                    '--eyebrow-tracking' => '.14em',
                    '--section-pad' => '76px',
                    '--grid-gap' => '18px',
                    '--shadow' => '0 1px 2px rgba(20, 32, 43, .06), 0 6px 18px rgba(20, 32, 43, .06)',
                    '--shadow-lg' => '0 2px 6px rgba(20, 32, 43, .07), 0 20px 44px rgba(20, 32, 43, .11)',
                ],
            ],
        ];
    }

    /**
     * The tokens for a preset id, or an empty array for the house look
     * and for anything unrecognised.
     *
     * Unknown ids fall back silently rather than throwing: a preset
     * removed from a future release must leave the site rendering, not
     * 500 every page because a settings row outlived its definition.
     *
     * @return array<string,string>
     */
    public static function tokens(?string $id): array
    {
        return self::all()[$id ?? '']['tokens'] ?? [];
    }

    /**
     * @return array<int,string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }
}
