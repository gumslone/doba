@extends('install.layout', [
    'title' => __('install.step_owner'),
    'intro' => __('install.owner_intro'),
    'step' => 'owner',
])

@section('content')
    <form method="POST" action="/install/owner" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">{{ __('install.your_name') }}</label>
            <input id="name" name="name" required value="{{ old('name') }}"
                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
        </div>

        <div>
            <label for="email" class="block text-sm font-medium">{{ __('install.your_email') }}</label>
            <input id="email" name="email" type="email" required value="{{ old('email') }}"
                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="password" class="block text-sm font-medium">{{ __('install.password') }}</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-medium">{{ __('install.password_again') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
        </div>

        {{-- Said before they choose, not after we reject: this account can
             read every guest's name, address and stay. --}}
        <p class="text-xs text-neutral-500">{{ __('install.password_rules') }}</p>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('install.continue') }}</button>
    </form>
@endsection
