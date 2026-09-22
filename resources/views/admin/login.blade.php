<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Login · Doba</title>
    @vite(['resources/css/app.css'])
</head>
<body class="doba-admin flex min-h-screen items-center justify-center bg-neutral-100">
    <form method="POST" action="/admin/login" class="w-full max-w-sm rounded-lg border border-neutral-200 bg-white p-8">
        @csrf
        <h1 class="text-lg font-semibold">Doba</h1>

        @if (\App\Support\Demo\Demo::enabled())
            <p class="mt-4 rounded border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900">
                {{ __('admin.demo_login_hint') }}<br>
                <span class="font-mono">{{ config('doba.admin.email') }}</span> · <span class="font-mono">{{ config('doba.admin.password') }}</span>
            </p>
        @endif

        <label for="email" class="mt-6 block text-sm font-medium">{{ __('admin.email') }}</label>
        <input type="email" id="email" name="email" required autofocus value="{{ old('email', \App\Support\Demo\Demo::enabled() ? config('doba.admin.email') : '') }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
        @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

        <label for="password" class="mt-4 block text-sm font-medium">{{ __('admin.password') }}</label>
        <input type="password" id="password" name="password" required
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">

        <label class="mt-4 flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
            {{ __('admin.remember_me') }}
        </label>

        <button type="submit" class="mt-6 w-full rounded bg-neutral-900 px-4 py-2 text-white">
            {{ __('admin.sign_in') }}
        </button>
    </form>

    @php $adminLocales = \App\Support\Routing\AdminLocale::available(); @endphp
    @if (count($adminLocales) > 1)
        <nav class="fixed bottom-6 left-0 right-0 flex flex-wrap justify-center gap-x-4 gap-y-1 px-4 text-sm text-neutral-500" aria-label="Language">
            @foreach ($adminLocales as $code => $name)
                <a href="/admin/login?lang={{ $code }}" hreflang="{{ $code }}" @class(['hover:text-neutral-900', 'font-semibold text-neutral-900' => app()->getLocale() === $code])>{{ $name }}</a>
            @endforeach
        </nav>
    @endif
</body>
</html>
