{{--
    The pre-Filament admin shell. Deliberately outside the theme system:
    a hotel's theme must never be able to restyle (or break) the tools
    used to fix the hotel's theme.

    A sidebar rather than a top bar because the section list outgrew one
    line: thirteen links across the top wrapped, truncated, and reordered
    themselves as the window changed, which is exactly when a hotelier
    misclicks. A vertical list has room to group, room to grow, and holds
    its position.
--}}
@php
    // Grouped by what somebody is trying to do, not by what the code
    // happens to be called: the front desk in the morning, the website in
    // the afternoon, the machinery rarely.
    $sections = [
        __('admin.group_today') => [
            ['/admin/front-desk', __('admin.front_desk'), 'admin/front-desk*'],
            ['/admin/availability', __('admin.availability'), 'admin/availability*'],
            ['/admin/invoices', __('admin.invoices'), 'admin/invoices*'],
        ],
        __('admin.group_selling') => [
            ['/admin/rate-plans', __('admin.rate_plans'), 'admin/rate-plans*'],
            ['/admin/extras', __('admin.extras'), 'admin/extras*'],
            ['/admin/promo-codes', __('admin.promo_codes'), 'admin/promo-codes*'],
            ['/admin/channels', __('admin.channels'), 'admin/channels*'],
        ],
        __('admin.group_website') => [
            ['/admin/pages', __('admin.pages'), 'admin/pages*'],
            ['/admin/events', __('admin.events'), 'admin/events*'],
            ['/admin/venues', __('admin.venues'), 'admin/venues*'],
            ['/admin/photos', __('admin.photos'), 'admin/photos*'],
            ['/admin/styles', __('admin.styles'), 'admin/styles*'],
        ],
        __('admin.group_system') => [
            ['/admin/mail', __('admin.mail'), 'admin/mail*'],
            ['/admin/update', __('admin.update'), 'admin/update*'],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'Admin' }} · Doba</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-neutral-100 text-neutral-900 antialiased">
    {{--
        Plain <details> for the mobile toggle. No framework, no JavaScript
        at all: the §14 CSP forbids 'unsafe-eval', and a navigation that
        stops working when a script fails to load is a navigation that
        strands somebody in an admin panel.
    --}}
    <details class="group border-b border-neutral-200 bg-white lg:hidden" id="admin-nav">
        <summary class="flex cursor-pointer items-center justify-between px-4 py-3 text-sm font-semibold">
            <span>Doba</span>
            <span class="text-neutral-500 group-open:hidden">{{ __('admin.menu') }}</span>
            <span class="hidden text-neutral-500 group-open:inline">{{ __('admin.close') }}</span>
        </summary>

        <nav class="px-2 pb-4" aria-label="{{ __('admin.sections') }}">
            @include('admin.partials.nav-sections', ['sections' => $sections])
        </nav>
    </details>

    <div class="lg:flex">
        <aside class="hidden w-60 shrink-0 border-r border-neutral-200 bg-white lg:sticky lg:top-0 lg:block lg:h-screen lg:overflow-y-auto">
            <div class="px-5 py-5">
                <a href="/admin/front-desk" class="text-lg font-semibold">Doba</a>
            </div>

            <nav class="px-2 pb-4" aria-label="{{ __('admin.sections') }}">
                @include('admin.partials.nav-sections', ['sections' => $sections])
            </nav>

            <div class="mt-auto space-y-1 border-t border-neutral-200 px-2 py-4 text-sm">
                <a href="/" target="_blank" rel="noopener"
                   class="block rounded px-3 py-1.5 text-neutral-500 hover:bg-neutral-50 hover:text-neutral-900">
                    {{ __('admin.view_site') }} ↗
                </a>
                <form method="POST" action="/admin/logout">
                    @csrf
                    <button type="submit"
                            class="block w-full rounded px-3 py-1.5 text-left text-neutral-500 hover:bg-neutral-50 hover:text-neutral-900">
                        {{ __('admin.logout') }}
                    </button>
                </form>
            </div>
        </aside>

        <main class="min-w-0 flex-1 px-4 py-8 lg:px-8">
            <div class="mx-auto max-w-5xl">
                {{-- Shown on every admin page until somebody confirms a test
                     message arrived. Mail is the one subsystem that fails
                     silently, so the warning is deliberately hard to ignore
                     and deliberately not dismissible. --}}
                @unless (app(App\Support\Mail\MailSettings::class)->isConfirmed() || request()->is('admin/mail*'))
                    <p class="mb-6 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        <a href="/admin/mail" class="font-medium underline">{{ __('admin.mail_unconfirmed') }}</a>
                        — {{ __('admin.mail_unconfirmed_hint') }}
                    </p>
                @endunless

                @if (session('saved') || session('status'))
                    <p class="mb-6 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">
                        {{ session('saved') ?? session('status') }}
                    </p>
                @endif

                @if ($errors->any())
                    <ul class="mb-6 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif

                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
