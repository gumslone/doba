@extends('install.layout', ['title' => __('install.step_hotel'), 'step' => 'hotel'])

@section('content')
    <form method="POST" action="/install/hotel" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">{{ __('install.hotel_name') }}</label>
            <input id="name" name="name" required value="{{ old('name') }}"
                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="email" class="block text-sm font-medium">{{ __('install.hotel_email') }}</label>
                <input id="email" name="email" type="email" required value="{{ old('email') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium">{{ __('install.hotel_phone') }}</label>
                <input id="phone" name="phone" value="{{ old('phone') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
        </div>

        <div>
            <label for="street" class="block text-sm font-medium">{{ __('install.street') }}</label>
            <input id="street" name="street" value="{{ old('street') }}"
                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="postal_code" class="block text-sm font-medium">{{ __('install.postal_code') }}</label>
                <input id="postal_code" name="postal_code" value="{{ old('postal_code') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="city" class="block text-sm font-medium">{{ __('install.city') }}</label>
                <input id="city" name="city" value="{{ old('city') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="country" class="block text-sm font-medium">{{ __('install.country') }}</label>
                <input id="country" name="country" value="{{ old('country') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="timezone" class="block text-sm font-medium">{{ __('install.timezone') }}</label>
                <select id="timezone" name="timezone" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach ($timezones as $timezone)
                        <option value="{{ $timezone }}" @selected(old('timezone', config('app.timezone')) === $timezone)>{{ $timezone }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="currency" class="block text-sm font-medium">{{ __('install.currency') }}</label>
                <input id="currency" name="currency" required maxlength="3"
                       value="{{ old('currency', config('doba.currency', 'EUR')) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 uppercase">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="checkin_from" class="block text-sm font-medium">{{ __('install.checkin_from') }}</label>
                <input id="checkin_from" name="checkin_from" type="time" required
                       value="{{ old('checkin_from', config('doba.checkin_from', '15:00')) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="checkout_until" class="block text-sm font-medium">{{ __('install.checkout_until') }}</label>
                <input id="checkout_until" name="checkout_until" type="time" required
                       value="{{ old('checkout_until', config('doba.checkout_until', '11:00')) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
        </div>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('install.continue') }}</button>
    </form>
@endsection
