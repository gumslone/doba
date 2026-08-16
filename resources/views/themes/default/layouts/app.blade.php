<!DOCTYPE html>
<html lang="{{ \App\Support\Routing\Localization::bcp47(app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-white text-neutral-900 antialiased">
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-neutral-900 focus:px-4 focus:py-2 focus:text-white">
        {{ __('common.skip_to_content') }}
    </a>

    @include('partials.header')

    <main id="content">
        @if (count($seo->getBreadcrumbs()) > 1)
            @include('partials.breadcrumbs')
        @endif

        {{ $slot ?? '' }}
        @yield('content')
    </main>

    @include('partials.footer')
</body>
</html>
