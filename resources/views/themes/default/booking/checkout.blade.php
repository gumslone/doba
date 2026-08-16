@extends('layouts.app')

@section('content')
    @php
        use App\Support\Money;
        use App\Support\Routing\Localization;

        $nights = (int) $stay['check_in']->diffInDays($stay['check_out']);
    @endphp

    <section class="mx-auto max-w-4xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('booking.checkout_title') }}</h1>

        <div class="mt-8 grid gap-8 lg:grid-cols-[3fr_2fr]">
            <form method="POST" action="{{ Localization::route('booking.store') }}" class="space-y-6">
                @csrf
                <input type="hidden" name="room_type" value="{{ $roomType->id }}">
                <input type="hidden" name="check_in" value="{{ $stay['check_in']->toDateString() }}">
                <input type="hidden" name="check_out" value="{{ $stay['check_out']->toDateString() }}">
                <input type="hidden" name="adults" value="{{ $stay['adults'] }}">
                <input type="hidden" name="children" value="{{ $stay['children'] }}">

                <fieldset class="space-y-4 rounded-lg border border-neutral-200 p-5">
                    <legend class="px-1 font-medium">{{ __('booking.your_details') }}</legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="first_name" class="block text-sm font-medium">{{ __('booking.first_name') }} *</label>
                            <input type="text" id="first_name" name="first_name" required maxlength="120"
                                   value="{{ old('first_name') }}" autocomplete="given-name"
                                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                            @error('first_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="last_name" class="block text-sm font-medium">{{ __('booking.last_name') }} *</label>
                            <input type="text" id="last_name" name="last_name" required maxlength="120"
                                   value="{{ old('last_name') }}" autocomplete="family-name"
                                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                            @error('last_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="email" class="block text-sm font-medium">{{ __('booking.email') }} *</label>
                            <input type="email" id="email" name="email" required maxlength="254"
                                   value="{{ old('email') }}" autocomplete="email"
                                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="phone" class="block text-sm font-medium">{{ __('booking.phone') }}</label>
                            <input type="tel" id="phone" name="phone" maxlength="64"
                                   value="{{ old('phone') }}" autocomplete="tel"
                                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                        </div>
                    </div>

                    <div>
                        <label for="guest_notes" class="block text-sm font-medium">{{ __('booking.notes') }}</label>
                        <textarea id="guest_notes" name="guest_notes" rows="3" maxlength="2000"
                                  placeholder="{{ __('booking.notes_hint') }}"
                                  class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ old('guest_notes') }}</textarea>
                    </div>
                </fieldset>

                @if ($extras->isNotEmpty())
                    <fieldset class="space-y-3 rounded-lg border border-neutral-200 p-5">
                        <legend class="px-1 font-medium">{{ __('booking.extras_title') }}</legend>

                        @foreach ($extras as $extra)
                            @continue ($extra->is_included)
                            <label class="flex items-baseline justify-between gap-4">
                                <span>
                                    <input type="checkbox" name="extras[{{ $extra->id }}]" value="1"
                                           @checked(old("extras.{$extra->id}"))>
                                    <span class="ml-1 font-medium">{{ $extra->t('name') }}</span>
                                    @if ($description = $extra->t('description'))
                                        <span class="mt-0.5 block pl-6 text-sm text-neutral-600">{{ $description }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 text-right text-sm">
                                    <strong>{{ Money::format($extra->price) }}</strong>
                                    <span class="block text-neutral-500">{{ __($extra->applies_per->label()) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif

                <fieldset class="space-y-3 rounded-lg border border-neutral-200 p-5">
                    {{-- Consent to the terms is a precondition of the booking;
                         marketing consent is separate and optional (§14 GDPR). --}}
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="terms" value="1" required class="mt-1" @checked(old('terms'))>
                        <span>{{ __('booking.terms') }}</span>
                    </label>
                    @error('terms') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <label class="flex items-start gap-2 text-sm text-neutral-600">
                        <input type="checkbox" name="marketing_consent" value="1" class="mt-1" @checked(old('marketing_consent'))>
                        <span>{{ __('booking.marketing') }}</span>
                    </label>
                </fieldset>

                <button type="submit" class="btn-primary rounded px-6 py-3 text-lg">{{ __('booking.book') }}</button>
            </form>

            <aside class="h-fit rounded-lg border border-neutral-200 bg-neutral-50 p-5">
                <h2 class="font-medium">{{ __('booking.summary') }}</h2>

                <x-responsive-image
                    :media="$roomType->coverImage()"
                    sizes="320px"
                    class="mt-4 aspect-[4/3] w-full rounded object-cover" />

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-neutral-500">{{ __('booking.room') }}</dt>
                        <dd class="text-right font-medium">{{ $roomType->t('name') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-neutral-500">{{ __('booking.dates') }}</dt>
                        <dd class="text-right">
                            {{ $stay['check_in']->translatedFormat('j M') }} –
                            {{ $stay['check_out']->translatedFormat('j M Y') }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-neutral-500">{{ __('booking.guests') }}</dt>
                        <dd class="text-right">{{ $stay['adults'] + $stay['children'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-neutral-200 pt-3 text-base">
                        <dt class="font-medium">
                            {{ $nights === 1 ? __('booking.night') : __('booking.nights', ['count' => $nights]) }}
                        </dt>
                        <dd class="text-right font-semibold">{{ Money::format($total) }}</dd>
                    </div>
                </dl>

                <p class="mt-3 text-xs text-neutral-500">{{ __('seo.direct_booking_note') }}</p>
            </aside>
        </div>
    </section>
@endsection
