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
    <form method="POST" action="/admin/login" class="w-full max-w-sm rounded-lg border border-neutral-200 bg-white p-8">
        @csrf
        <h1 class="text-lg font-semibold">Doba</h1>

        <label for="email" class="mt-6 block text-sm font-medium">{{ __('admin.email') }}</label>
        <input type="email" id="email" name="email" required autofocus value="{{ old('email') }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
        @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

        <label for="password" class="mt-4 block text-sm font-medium">{{ __('admin.password') }}</label>
        <input type="password" id="password" name="password" required
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">

        <button type="submit" class="mt-6 w-full rounded bg-neutral-900 px-4 py-2 text-white">
            {{ __('admin.sign_in') }}
        </button>
    </form>
</body>
</html>
