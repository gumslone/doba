<!DOCTYPE html>
<html lang="{{ \App\Support\Routing\Localization::bcp47(app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body>
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[70] focus:rounded focus:bg-[var(--doba-primary)] focus:px-4 focus:py-2 focus:text-white">
        {{ __('common.skip_to_content') }}
    </a>

    @include('partials.topbar')
    @include('partials.header')

    <main id="content">
        @if (count($seo->getBreadcrumbs()) > 1 && ! ($hideBreadcrumbs ?? false))
            @include('partials.breadcrumbs')
        @endif

        {{ $slot ?? '' }}
        @yield('content')
    </main>

    @include('partials.footer')
    @include('partials.cookie')
</body>
</html>
