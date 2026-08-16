@extends('layouts.app')

@section('content')
    @php
        use App\Support\Money;
        use App\Support\Routing\Localization;
    @endphp

    <section class="mx-auto max-w-5xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('booking.search_title') }}</h1>

        <div class="mt-6 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
            @include('booking._form')
        </div>

        @if (session('booking_error'))
            <p class="mt-6 rounded border border-amber-200 bg-amber-50 p-4 text-amber-900" role="status">
                {{ session('booking_error') }}
            </p>
        @endif

        @if ($error)
            <p class="mt-6 rounded border border-red-200 bg-red-50 p-4 text-red-800" role="alert">
                {{ __($error) }}
            </p>
        @elseif ($stay)
            @php $nights = (int) $stay['check_in']->diffInDays($stay['check_out']); @endphp

            <p class="mt-6 text-sm text-neutral-600">
                {{ $stay['check_in']->translatedFormat('j M Y') }} –
                {{ $stay['check_out']->translatedFormat('j M Y') }} ·
                {{ $nights === 1 ? __('booking.night') : __('booking.nights', ['count' => $nights]) }} ·
                {{ __('booking.guests') }}: {{ $stay['adults'] + $stay['children'] }}
            </p>

            @if ($offers === [])
                <p class="mt-6 rounded border border-neutral-200 p-6 text-neutral-600">
                    {{ __('booking.no_offers') }}
                    <a href="{{ Localization::route('contact') }}" class="link-accent underline">{{ __('contact.title') }}</a>
                </p>
            @else
                <ul class="mt-8 space-y-6">
                    @foreach ($offers as $offer)
                        @php $roomType = $offer['room_type']; @endphp
                        <li class="grid gap-5 rounded-lg border border-neutral-200 p-5 sm:grid-cols-[1fr_2fr_auto]">
                            <x-responsive-image
                                :media="$roomType->coverImage()"
                                sizes="(max-width: 640px) 100vw, 240px"
                                class="aspect-[4/3] w-full rounded object-cover" />

                            <div>
                                <h2 class="text-lg font-medium">
                                    <a href="{{ Localization::route('rooms.show', ['slug' => $roomType->slug()]) }}"
                                       class="hover:underline">{{ $roomType->t('name') }}</a>
                                </h2>
                                <p class="mt-1 text-sm text-neutral-500">
                                    {{ __('common.guests', ['count' => $roomType->max_occupancy]) }}
                                    @if ($roomType->size_sqm) · {{ __('common.sqm', ['size' => $roomType->size_sqm]) }} @endif
                                    @if ($roomType->bed_setup) · {{ $roomType->bed_setup }} @endif
                                </p>
                                @if ($short = $roomType->t('short_description'))
                                    <p class="mt-2 text-neutral-700">{{ $short }}</p>
                                @endif
                                @if ($offer['units_left'] <= 3 && $offer['units_left'] < $roomType->total_units)
                                    {{-- Genuine scarcity only. Counted from confirmed bookings,
                                         never holds (§6) — and only once some units have actually
                                         sold, or a hotel with three suites would permanently
                                         advertise "only 3 left" with nothing booked at all. --}}
                                    <p class="mt-2 text-sm font-medium text-amber-700">
                                        {{ __('booking.only_left', ['count' => $offer['units_left']]) }}
                                    </p>
                                @endif
                            </div>

                            <div class="text-right">
                                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('booking.stay_total') }}</p>
                                <p class="text-2xl font-semibold">{{ Money::format($offer['total']) }}</p>
                                <p class="text-sm text-neutral-500">
                                    {{ Money::format($offer['per_night']) }} {{ __('common.per_night') }}
                                </p>
                                <a href="{{ Localization::route('booking.checkout', array_merge([
                                        'room_type' => $roomType->id,
                                    ], [
                                        'check_in' => $stay['check_in']->toDateString(),
                                        'check_out' => $stay['check_out']->toDateString(),
                                        'adults' => $stay['adults'],
                                        'children' => $stay['children'],
                                    ])) }}"
                                   class="btn-primary mt-4 inline-block rounded px-5 py-2.5">
                                    {{ __('booking.choose') }}
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>
@endsection
