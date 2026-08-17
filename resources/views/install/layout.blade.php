{{--
    The wizard shell. Outside the theme system and outside the admin
    shell: it runs before either exists, and must render on a copy of
    Doba where nothing has been configured at all.
--}}
@php use App\Support\Install\Installer; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? __('install.title') }} · Doba</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-neutral-100 text-neutral-900 antialiased">
    <div class="mx-auto max-w-2xl px-4 py-10">
        <p class="mb-6 text-lg font-semibold">Doba</p>

        @isset($step)
            <ol class="mb-8 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                @foreach (Installer::STEPS as $name)
                    @php $done = in_array($name, $completed ?? [], true); @endphp
                    <li @class([
                        'font-semibold text-neutral-900' => $name === $step,
                        'text-neutral-400' => $name !== $step && ! $done,
                        'text-neutral-500' => $done && $name !== $step,
                    ])>
                        {{ $done ? '✓' : '' }} {{ __('install.step_'.$name) }}
                    </li>
                @endforeach
            </ol>
        @endisset

        <div class="rounded-lg border border-neutral-200 bg-white p-6">
            <h1 class="text-xl font-semibold">{{ $title ?? __('install.title') }}</h1>

            @isset($intro)
                <p class="mt-1 text-sm text-neutral-600">{{ $intro }}</p>
            @endisset

            @if ($errors->any())
                <ul class="mt-5 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-6">
                @yield('content')
            </div>
        </div>
    </div>
</body>
</html>
