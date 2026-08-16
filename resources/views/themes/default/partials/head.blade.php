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

@foreach ($seo->schemas() as $schema)
    @jsonld($schema)
@endforeach

@stack('head')
