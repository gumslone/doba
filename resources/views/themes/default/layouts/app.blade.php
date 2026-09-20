<!DOCTYPE html>
<html lang="{{ \App\Support\Routing\Localization::bcp47(app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body>
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[70] focus:rounded focus:bg-[var(--doba-primary)] focus:px-4 focus:py-2 focus:text-white">
        {{ __('common.skip_to_content') }}
    </a>

    @if (\App\Support\Demo\Demo::enabled())
        {{-- The public demo says so on every page, and hands out the key:
             standing behind the desk is what convinces a hotelier (§22). --}}
        <div style="background:#0c4a6e;color:#fff;font:500 .85rem/1.4 var(--doba-font-body,system-ui);padding:.55rem 1rem;text-align:center">
            {{ __('common.demo_strip') }}
            <a href="/admin" style="color:#fff;text-decoration:underline;margin-left:.4rem">{{ __('common.demo_strip_admin') }}</a>
            <span style="opacity:.8;margin-left:.4rem;font-family:ui-monospace,monospace">{{ config('doba.admin.email') }} / {{ config('doba.admin.password') }}</span>
        </div>
    @endif

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
