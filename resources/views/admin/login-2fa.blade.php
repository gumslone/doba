<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Login · Doba</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-neutral-100">
    <form method="POST" action="/admin/login/2fa" class="mx-auto mt-24 w-full max-w-sm rounded border border-neutral-200 bg-white p-6">
        @csrf
        <h1 class="text-lg font-semibold">{{ __('admin.two_factor_title') }}</h1>
        <p class="mt-2 text-sm text-neutral-600">{{ __('admin.two_factor_hint') }}</p>

        <label for="code" class="mt-6 block text-sm font-medium">{{ __('admin.two_factor_code') }}</label>
        <input id="code" name="code" required autofocus autocomplete="one-time-code" inputmode="numeric"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono text-lg tracking-widest">
        @error('code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

        <button type="submit" class="mt-6 w-full rounded bg-neutral-900 px-4 py-2 text-white">
            {{ __('admin.sign_in') }}
        </button>
    </form>
</body>
</html>
