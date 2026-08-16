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

        @if ($inclusions = $roomType->inclusionsByCategory())
            {{-- The visible twin of the amenityFeature entries in the room's
                 JSON-LD — same rule as breadcrumbs and FAQs. Grouped, because
                 twenty ungrouped ticks are a wall a guest skims past while
                 "Bathroom" answers the question they arrived with. --}}
            <section class="mt-10">
                <h2 class="text-xl font-semibold tracking-tight">{{ __('extras.includes') }}</h2>

                <div class="mt-4 grid gap-6 sm:grid-cols-2">
                    @foreach ($inclusions as $category => $amenities)
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
                                {{ __('extras.category_'.$category) }}
                            </h3>
                            <ul class="mt-2 space-y-1 text-neutral-700">
                                @foreach ($amenities as $amenity)
                                    <li class="flex items-center gap-2">
                                        <span aria-hidden="true" style="color: var(--doba-accent)">✓</span>
                                        {{ $amenity->t('name') }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @php $extras = $roomType->availableExtras(); @endphp

        @if ($extras->isNotEmpty())
            <section class="mt-10">
                <h2 class="text-xl font-semibold tracking-tight">{{ __('extras.title') }}</h2>
                <p class="mt-1 text-sm text-neutral-500">{{ __('extras.intro') }}</p>

                <ul class="mt-4 divide-y divide-neutral-200 rounded-lg border border-neutral-200">
                    @foreach ($extras as $extra)
                        <li class="flex items-baseline justify-between gap-4 px-4 py-3">
                            <div>
                                <p class="font-medium">{{ $extra->t('name') }}</p>
                                @if ($description = $extra->t('description'))
                                    <p class="mt-0.5 text-sm text-neutral-600">{{ $description }}</p>
                                @endif
                            </div>
                            <p class="shrink-0 text-right text-sm">
                                @if ($extra->is_included)
                                    <span class="font-medium" style="color: var(--doba-accent)">{{ __('extras.included') }}</span>
                                @else
                                    <strong>{{ \App\Support\Money::format($extra->price) }}</strong>
                                    <span class="block text-neutral-500">{{ __($extra->applies_per->label()) }}</span>
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            </section>
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
