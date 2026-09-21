@extends('layouts.app')

@section('content')
    @php
        use App\Support\Countries;
        use App\Support\Routing\Localization;

        $countries = Countries::names();
        $field = 'mt-1 w-full rounded border border-neutral-300 px-3 py-2';
        $label = 'block text-sm font-medium';
        $value = fn (int $i, string $key, ?string $fallback = null) => old("party.$i.$key", $party[$i][$key] ?? $fallback);
    @endphp

    <section class="mx-auto max-w-3xl px-4 py-12">
        <p class="text-sm"><a href="{{ Localization::route('booking.manage', ['reference' => $booking->reference, 'token' => $token]) }}" class="text-neutral-500 hover:underline">&larr; {{ __('booking.manage_title') }}</a></p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ __('checkin.title') }}</h1>
        <p class="mt-3 text-neutral-600">{{ __('checkin.lede') }}</p>

        @if ($errors->any())
            <p class="mt-6 rounded border border-amber-200 bg-amber-50 p-4 text-amber-900" role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ Localization::route('booking.checkin.store', ['reference' => $booking->reference, 'token' => $token]) }}" class="mt-8 space-y-8">
            @csrf

            @foreach (range(0, $people - 1) as $i)
                <fieldset class="rounded-lg border border-neutral-200 p-5">
                    <legend class="px-2 font-medium">{{ $i === 0 ? __('checkin.you') : __('checkin.guest_n', ['n' => $i + 1]) }}</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="{{ $label }}" for="p{{ $i }}_first">{{ __('checkin.first_name') }} *</label>
                            <input id="p{{ $i }}_first" name="party[{{ $i }}][first_name]" required maxlength="80" autocomplete="{{ $i === 0 ? 'given-name' : 'off' }}" value="{{ $value($i, 'first_name', $i === 0 ? $booking->guest?->first_name : null) }}" class="{{ $field }}"></div>
                        <div><label class="{{ $label }}" for="p{{ $i }}_last">{{ __('checkin.last_name') }} *</label>
                            <input id="p{{ $i }}_last" name="party[{{ $i }}][last_name]" required maxlength="80" autocomplete="{{ $i === 0 ? 'family-name' : 'off' }}" value="{{ $value($i, 'last_name', $i === 0 ? $booking->guest?->last_name : null) }}" class="{{ $field }}"></div>
                        <div><label class="{{ $label }}" for="p{{ $i }}_dob">{{ __('checkin.date_of_birth') }} *</label>
                            <input type="date" id="p{{ $i }}_dob" name="party[{{ $i }}][date_of_birth]" required max="{{ now()->toDateString() }}" value="{{ $value($i, 'date_of_birth') }}" class="{{ $field }}"></div>
                        <div><label class="{{ $label }}" for="p{{ $i }}_nat">{{ __('checkin.nationality') }} *</label>
                            <select id="p{{ $i }}_nat" name="party[{{ $i }}][nationality]" required class="{{ $field }}">
                                <option value=""></option>
                                @foreach ($countries as $code => $name)
                                    <option value="{{ $code }}" @selected($value($i, 'nationality', $i === 0 ? $booking->guest?->country : null) === $code)>{{ $name }}</option>
                                @endforeach
                            </select></div>

                        @if ($i === 0)
                            <div class="sm:col-span-2"><label class="{{ $label }}" for="p0_street">{{ __('checkin.street') }} *</label>
                                <input id="p0_street" name="party[0][street]" required maxlength="160" autocomplete="street-address" value="{{ $value(0, 'street', $booking->guest?->address) }}" class="{{ $field }}"></div>
                            <div><label class="{{ $label }}" for="p0_zip">{{ __('checkin.postal_code') }} *</label>
                                <input id="p0_zip" name="party[0][postal_code]" required maxlength="20" autocomplete="postal-code" value="{{ $value(0, 'postal_code', $booking->guest?->postal_code) }}" class="{{ $field }}"></div>
                            <div><label class="{{ $label }}" for="p0_city">{{ __('checkin.city') }} *</label>
                                <input id="p0_city" name="party[0][city]" required maxlength="120" autocomplete="address-level2" value="{{ $value(0, 'city', $booking->guest?->city) }}" class="{{ $field }}"></div>
                            <div class="sm:col-span-2"><label class="{{ $label }}" for="p0_country">{{ __('checkin.country') }} *</label>
                                <select id="p0_country" name="party[0][country]" required class="{{ $field }}">
                                    <option value=""></option>
                                    @foreach ($countries as $code => $name)
                                        <option value="{{ $code }}" @selected($value(0, 'country', $booking->guest?->country) === $code)>{{ $name }}</option>
                                    @endforeach
                                </select></div>
                        @endif

                        @if ($documents !== 'none')
                            <div><label class="{{ $label }}" for="p{{ $i }}_doctype">{{ __('checkin.document_type') }}@if ($documents === 'all') *@endif</label>
                                <select id="p{{ $i }}_doctype" name="party[{{ $i }}][document_type]" @required($documents === 'all') class="{{ $field }}">
                                    <option value=""></option>
                                    @foreach (['passport', 'id_card', 'other'] as $type)
                                        <option value="{{ $type }}" @selected($value($i, 'document_type') === $type)>{{ __('checkin.doc_'.$type) }}</option>
                                    @endforeach
                                </select></div>
                            <div><label class="{{ $label }}" for="p{{ $i }}_docno">{{ __('checkin.document_number') }}@if ($documents === 'all') *@endif</label>
                                <input id="p{{ $i }}_docno" name="party[{{ $i }}][document_number]" maxlength="40" autocomplete="off" @required($documents === 'all') value="{{ $value($i, 'document_number') }}" class="{{ $field }}">
                                @if ($documents === 'foreign') <p class="mt-1 text-xs text-neutral-500">{{ __('checkin.doc_hint') }}</p> @endif</div>
                        @endif
                    </div>
                </fieldset>
            @endforeach

            <div class="max-w-xs">
                <label class="{{ $label }}" for="arrival_time">{{ __('checkin.arrival_time') }}</label>
                <input type="time" id="arrival_time" name="arrival_time" value="{{ old('arrival_time', $booking->arrival_time) }}" class="{{ $field }}">
            </div>

            <p class="text-xs text-neutral-500">{{ __('checkin.privacy', ['days' => config('doba.checkin.retain_days')]) }}</p>

            <button type="submit" class="btn-primary rounded px-6 py-3">{{ __('checkin.save') }}</button>
        </form>
    </section>
@endsection
