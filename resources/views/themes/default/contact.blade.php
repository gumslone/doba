@extends('layouts.app')

@section('content')
    @php
        use App\Support\Routing\Localization;
    @endphp

    <section class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('contact.title') }}</h1>

        <div class="mt-4 space-y-1 text-neutral-600">
            @if ($street = $hotel->get('contact.street'))
                <p>{{ $hotel->name }} · {{ $street }}, {{ $hotel->get('contact.postal_code') }} {{ $hotel->get('contact.city') }}</p>
            @endif
            @if ($phone = $hotel->get('contact.phone'))
                <p><a href="tel:{{ preg_replace('/[^+0-9]/', '', $phone) }}" class="underline">{{ $phone }}</a></p>
            @endif
        </div>

        @if (session('enquiry_sent'))
            <p class="mt-8 rounded border border-green-200 bg-green-50 p-4 text-green-800" role="status">
                {{ __('contact.sent') }}
            </p>
        @endif

        <form method="POST" action="{{ Localization::route('contact.submit') }}" class="mt-8 grid gap-5">
            @csrf

            {{-- Honeypot: invisible to humans, irresistible to bots. Hidden
                 with a class rather than type=hidden, which many bots skip. --}}
            <div class="sr-only" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <input type="hidden" name="_t" value="{{ $timingToken }}">

            <div>
                <label for="name" class="block text-sm font-medium">{{ __('contact.name') }} *</label>
                <input type="text" id="name" name="name" required maxlength="120" value="{{ old('name') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2" autocomplete="name">
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="email" class="block text-sm font-medium">{{ __('contact.email') }} *</label>
                    <input type="email" id="email" name="email" required maxlength="254" value="{{ old('email') }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2" autocomplete="email">
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="block text-sm font-medium">{{ __('contact.phone') }}</label>
                    <input type="tel" id="phone" name="phone" maxlength="64" value="{{ old('phone') }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2" autocomplete="tel">
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="check_in" class="block text-sm font-medium">{{ __('contact.check_in') }}</label>
                    <input type="date" id="check_in" name="check_in" value="{{ old('check_in') }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="check_out" class="block text-sm font-medium">{{ __('contact.check_out') }}</label>
                    <input type="date" id="check_out" name="check_out" value="{{ old('check_out') }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @error('check_out') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="message" class="block text-sm font-medium">{{ __('contact.message') }} *</label>
                <textarea id="message" name="message" required rows="6" maxlength="5000"
                          class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ old('message') }}</textarea>
                @error('message') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <p>
                <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">
                    {{ __('contact.send') }}
                </button>
            </p>
        </form>
    </section>
@endsection
