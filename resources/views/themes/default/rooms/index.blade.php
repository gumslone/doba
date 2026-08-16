@extends('layouts.app')

@section('content')
    @php
        use App\Support\Money;
        use App\Support\Routing\Localization;
    @endphp

    <section class="mx-auto max-w-6xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('seo.rooms.title') }}</h1>

        @if ($roomTypes->isEmpty())
            <p class="mt-4 text-neutral-600">{{ __('common.no_rooms_yet') }}</p>
        @else
            <ul class="mt-10 space-y-10">
                @foreach ($roomTypes as $roomType)
                    @continue (! $roomType->slug())
                    <li class="grid gap-6 sm:grid-cols-[2fr_3fr]">
                        <a href="{{ Localization::route('rooms.show', ['slug' => $roomType->slug()]) }}" tabindex="-1" aria-hidden="true">
                            <x-responsive-image
                                :media="$roomType->coverImage()"
                                :eager="$loop->first"
                                sizes="(max-width: 640px) 100vw, 40vw"
                                class="aspect-[4/3] w-full rounded-lg object-cover" />
                        </a>

                        <div>
                            <h2 class="text-xl font-medium">
                                <a href="{{ Localization::route('rooms.show', ['slug' => $roomType->slug()]) }}" class="hover:underline">
                                    {{ $roomType->t('name') }}
                                </a>
                            </h2>

                            <p class="mt-1 text-sm text-neutral-500">
                                {{ __('common.guests', ['count' => $roomType->max_occupancy]) }}
                                @if ($roomType->size_sqm) · {{ __('common.sqm', ['size' => $roomType->size_sqm]) }} @endif
                                @if ($roomType->bed_setup) · {{ $roomType->bed_setup }} @endif
                            </p>

                            @if ($short = $roomType->t('short_description'))
                                <p class="mt-3 text-neutral-700">{{ $short }}</p>
                            @endif

                            @if ($roomType->default_rate)
                                <p class="mt-4">
                                    {{ __('common.from') }}
                                    <strong>{{ Money::format($roomType->default_rate) }}</strong>
                                    {{ __('common.per_night') }}
                                </p>
                            @endif

                            <p class="mt-4">
                                <a href="{{ Localization::route('rooms.show', ['slug' => $roomType->slug()]) }}"
                                   class="inline-block btn-primary rounded px-4 py-2 text-sm">
                                    {{ __('common.view_room') }}
                                </a>
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
