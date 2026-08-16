{{--
    The pre-Filament admin shell. Deliberately outside the theme system:
    a hotel's theme must never be able to restyle (or break) the tools
    used to fix the hotel's theme.
--}}
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
    <header class="border-b border-neutral-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
            <nav class="flex items-center gap-5 text-sm font-medium">
                <span class="font-semibold">Doba</span>
                <a href="/admin/availability" @class(['underline' => request()->is('admin/availability*')])>{{ __('admin.availability') }}</a>
                <a href="/admin/pages" @class(['underline' => request()->is('admin/pages*')])>{{ __('admin.pages') }}</a>
                <a href="/admin/events" @class(['underline' => request()->is('admin/events*')])>{{ __('admin.events') }}</a>
                <a href="/admin/rate-plans" @class(['underline' => request()->is('admin/rate-plans*')])>{{ __('admin.rate_plans') }}</a>
                <a href="/admin/extras" @class(['underline' => request()->is('admin/extras*')])>{{ __('admin.extras') }}</a>
                <a href="/admin/photos" @class(['underline' => request()->is('admin/photos*')])>{{ __('admin.photos') }}</a>
                <a href="/admin/styles" @class(['underline' => request()->is('admin/styles*')])>{{ __('admin.styles') }}</a>
            </nav>
            <div class="flex items-center gap-4 text-sm">
                <a href="/" target="_blank" class="text-neutral-500 hover:underline">{{ __('admin.view_site') }}</a>
                <form method="POST" action="/admin/logout">
                    @csrf
                    <button type="submit" class="text-neutral-500 hover:underline">{{ __('admin.logout') }}</button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-8">
        @if (session('saved'))
            <p class="mb-6 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">
                {{ session('saved') }}
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
    </main>
</body>
</html>
