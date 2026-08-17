{{--
    Everything a search engine and a social crawler read.

    Order is deliberate: charset and viewport first (a late charset makes the
    parser restart), then the render-blocking CSS, then metadata. Nothing
    here loads from a third-party origin — self-hosted fonts keep the LCP
    budget in §11 and keep a German hotel's privacy policy honest.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>{{ $seo->renderTitle() }}</title>

@if ($description = $seo->renderDescription())
    <meta name="description" content="{{ $description }}">
@endif

@if ($seo->isNoindex())
    <meta name="robots" content="noindex, nofollow">
@else
    {{-- max-image-preview:large is what puts a photograph next to the result
         instead of a thumbnail, on a site whose product is photographs. --}}
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
@endif

<link rel="canonical" href="{{ $seo->getCanonical() }}">

@foreach ($seo->getAlternates() as $alternateLocale => $alternateUrl)
    <link rel="alternate" hreflang="{{ \App\Support\Routing\Localization::bcp47($alternateLocale) }}" href="{{ $alternateUrl }}">
@endforeach

@if ($xDefault = \App\Support\Routing\Localization::xDefault($seo->getAlternates()))
    <link rel="alternate" hreflang="x-default" href="{{ $xDefault }}">
@endif

<meta property="og:type" content="{{ $seo->getType() }}">
<meta property="og:site_name" content="{{ $seo->getSiteName() }}">
<meta property="og:title" content="{{ $seo->renderTitle() }}">
<meta property="og:url" content="{{ $seo->getCanonical() }}">
<meta property="og:locale" content="{{ str_replace('-', '_', \App\Support\Routing\Localization::bcp47(app()->getLocale())) }}">
@foreach (array_keys($seo->getAlternates()) as $alternateLocale)
    @if ($alternateLocale !== app()->getLocale())
        <meta property="og:locale:alternate" content="{{ str_replace('-', '_', \App\Support\Routing\Localization::bcp47($alternateLocale)) }}">
    @endif
@endforeach
@if ($description = $seo->renderDescription())
    <meta property="og:description" content="{{ $description }}">
@endif
@if ($image = $seo->getImage())
    <meta property="og:image" content="{{ $image }}">
    <meta name="twitter:card" content="summary_large_image">
@else
    <meta name="twitter:card" content="summary">
@endif
@if ($twitter = config('doba.seo.twitter_site'))
    <meta name="twitter:site" content="{{ $twitter }}">
@endif

@if ($themeColor = $hotel->get('branding.color_primary'))
    <meta name="theme-color" content="{{ $themeColor }}">
@endif

<link rel="icon" href="{{ $hotel->get('branding.favicon') ? Storage::disk('public')->url($hotel->get('branding.favicon')) : '/favicon.ico' }}" sizes="any">

@vite(['resources/css/app.css', 'resources/js/app.js'])

@php
    $brandingFonts = \App\Http\Controllers\Admin\StyleController::FONT_STACKS;
    // The preset first, the hotelier's own picks second: choosing a look
    // is a starting point, never a cage — their brand colour still wins.
    $presetVars = \App\Support\Theme\StylePreset::tokens($hotel->get('branding.preset'));
    $brandingVars = array_filter([
        '--doba-primary' => $hotel->get('branding.color_primary'),
        '--doba-accent' => $hotel->get('branding.color_accent'),
        '--doba-font-heading' => $brandingFonts[$hotel->get('branding.font_heading', '')] ?? null,
        '--doba-font-body' => $brandingFonts[$hotel->get('branding.font_body', '')] ?? null,
    ]);
    $brandingCss = (string) $hotel->get('branding.custom_css', '');
@endphp

@if ($presetVars !== [] || $brandingVars !== [])
    {{-- Settings-driven styling (§3): colours, fonts and the whole style
         preset are data, never a theme file. Defaults live in app.css;
         only overrides are emitted, so the house look costs nothing. --}}
    <style>:root{ @foreach (array_merge($presetVars, $brandingVars) as $var => $value){{ $var }}:{{ $value }}; @endforeach }</style>
@endif

@if ($brandingCss !== '')
    {{-- Every "<" is emitted as the CSS escape \3c: inside a string it
         denotes the same character, everywhere else a literal "<" was
         invalid CSS anyway — and no markup can form inside the block. --}}
    <style>{!! str_replace('<', '\\3c ', $brandingCss) !!}</style>
@endif

@foreach ($seo->schemas() as $schema)
    @jsonld($schema)
@endforeach

@stack('head')
