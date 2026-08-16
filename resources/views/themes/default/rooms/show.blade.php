@extends('layouts.app')

@section('content')
    @php
        use App\Support\Money;
    @endphp

    <article class="mx-auto max-w-4xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ $roomType->t('name') }}</h1>

        <p class="mt-2 text-sm text-neutral-500">
            {{ __('common.guests', ['count' => $roomType->max_occupancy]) }}
            @if ($roomType->size_sqm) · {{ __('common.sqm', ['size' => $roomType->size_sqm]) }} @endif
            @if ($roomType->bed_setup) · {{ $roomType->bed_setup }} @endif
        </p>

        <x-responsive-image
            :media="$roomType->coverImage()"
            :eager="true"
            sizes="(max-width: 1024px) 100vw, 896px"
            class="mt-8 aspect-[16/9] w-full rounded-lg object-cover" />

        @if ($description = $roomType->t('description'))
            <div class="prose mt-8 max-w-none">{!! $description !!}</div>
        @endif

        @if ($roomType->default_rate)
            <p class="mt-8 text-lg">
                {{ __('common.from') }}
                <strong>{{ Money::format($roomType->default_rate) }}</strong>
                {{ __('common.per_night') }}
            </p>
            <p class="mt-1 text-sm text-neutral-500">{{ __('seo.direct_booking_note') }}</p>
        @endif

        @if ($roomType->media->count() > 1)
            <ul class="mt-10 grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach ($roomType->media->skip(0) as $image)
                    @continue ($image->is($roomType->coverImage()))
                    <li>
                        <x-responsive-image :media="$image" sizes="(max-width: 640px) 50vw, 280px"
                                            class="aspect-[4/3] w-full rounded object-cover" />
                    </li>
                @endforeach
            </ul>
        @endif
    </article>
@endsection
