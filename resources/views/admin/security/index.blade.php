@extends('admin.layout', ['title' => __('admin.security')])

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.security') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-neutral-600">{{ __('admin.security_intro') }}</p>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif
    @if ($errors->any())
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ $errors->first() }}</p>
    @endif

    @if ($recoveryCodes)
        <section class="mb-8 rounded border border-amber-300 bg-amber-50 p-5">
            <h2 class="font-medium">{{ __('admin.recovery_codes') }}</h2>
            <p class="mt-1 text-sm text-amber-900">{{ __('admin.recovery_codes_hint') }}</p>
            <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-sm sm:grid-cols-4">
                @foreach ($recoveryCodes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mb-8 rounded border border-neutral-200 bg-white p-5">
        <h2 class="font-medium">{{ __('admin.two_factor') }}</h2>

        @if ($enabled)
            <p class="mt-2 text-sm text-green-800">{{ __('admin.two_factor_on', ['count' => $recoveryLeft]) }}</p>

            <div class="mt-4 flex flex-wrap gap-6">
                <form method="POST" action="/admin/security/2fa/recovery" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <label class="block text-xs text-neutral-500">{{ __('admin.password') }}</label>
                        <input type="password" name="password" required autocomplete="current-password" class="mt-1 rounded border border-neutral-300 px-3 py-1.5 text-sm">
                    </div>
                    <button type="submit" class="rounded border border-neutral-300 px-3 py-1.5 text-sm">{{ __('admin.recovery_regenerate') }}</button>
                </form>

                <form method="POST" action="/admin/security/2fa/disable" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <label class="block text-xs text-neutral-500">{{ __('admin.password') }}</label>
                        <input type="password" name="password" required autocomplete="current-password" class="mt-1 rounded border border-neutral-300 px-3 py-1.5 text-sm">
                    </div>
                    <button type="submit" class="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700">{{ __('admin.two_factor_turn_off') }}</button>
                </form>
            </div>
        @else
            <p class="mt-2 text-sm text-neutral-600">{{ __('admin.two_factor_off') }}</p>

            <div class="mt-4 grid gap-6 sm:grid-cols-2">
                <div>
                    <div class="inline-block rounded border border-neutral-200 bg-white p-2">{!! $qr !!}</div>
                    <p class="mt-2 text-xs text-neutral-500">{{ __('admin.two_factor_manual') }} <code class="font-mono">{{ $pendingSecret }}</code></p>
                </div>
                <form method="POST" action="/admin/security/2fa/enable">
                    @csrf
                    <label for="code" class="block text-sm font-medium">{{ __('admin.two_factor_code') }}</label>
                    <input id="code" name="code" required inputmode="numeric" autocomplete="one-time-code"
                           class="mt-1 w-40 rounded border border-neutral-300 px-3 py-2 font-mono text-lg tracking-widest">
                    <p class="mt-1 text-xs text-neutral-500">{{ __('admin.two_factor_confirm_hint') }}</p>
                    <button type="submit" class="mt-3 rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.two_factor_turn_on') }}</button>
                </form>
            </div>
        @endif
    </section>

    <section class="rounded border border-neutral-200 bg-white p-5">
        <h2 class="font-medium">{{ __('admin.change_password') }}</h2>
        <form method="POST" action="/admin/security/password" class="mt-3 grid max-w-md gap-3">
            @csrf
            <div>
                <label class="block text-sm font-medium">{{ __('admin.current_password') }}</label>
                <input type="password" name="current_password" required autocomplete="current-password" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium">{{ __('admin.new_password') }}</label>
                <input type="password" name="password" required autocomplete="new-password" minlength="12" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium">{{ __('admin.new_password_confirm') }}</label>
                <input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div><button type="submit" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.save') }}</button></div>
        </form>
    </section>
@endsection
