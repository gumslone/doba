@extends('layouts.app')

@section('content')
    @php
        use App\Support\Money;
        use App\Support\Routing\Localization;
    @endphp

    <section class="mx-auto max-w-6xl px-4 py-16">
        {{-- Exactly one h1 per page, carrying the words the page should rank
             for. The hotel name alone ranks for the hotel name, which is
             traffic the hotel already had. --}}
        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
            {{ $hotel->get('seo.h1') ?: $hotel->name }}
        </h1>

        @if ($tagline = $hotel->get('general.tagline'))
            <p class="mt-4 max-w-2xl text-lg text-neutral-600">{{ $tagline }}</p>
        @endif

        <p class="mt-6 text-sm text-neutral-500">
            {{ __('common.check_in_from', ['time' => config('doba.checkin_from')]) }} ·
            {{ __('common.check_out_until', ['time' => config('doba.checkout_until')]) }}
        </p>
    </section>

    <section class="mx-auto max-w-6xl px-4 pb-16">
        <h2 class="text-2xl font-semibold tracking-tight">{{ __('common.our_rooms') }}</h2>

        @if ($roomTypes->isEmpty())
            <p class="mt-4 text-neutral-600">{{ __('common.no_rooms_yet') }}</p>
        @else
            <ul class="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($roomTypes as $roomType)
                    @continue (! $roomType->slug())
                    <li class="overflow-hidden rounded-lg border border-neutral-200">
                        <a href="{{ Localization::route('rooms.show', ['slug' => $roomType->slug()]) }}">
                            <x-responsive-image
                                :media="$roomType->coverImage()"
                                :eager="$loop->first"
                                sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 384px"
                                class="aspect-[4/3] w-full object-cover" />

                            <div class="p-4">
                                <h3 class="font-medium">{{ $roomType->t('name') }}</h3>

                                @if ($short = $roomType->t('short_description'))
                                    <p class="mt-1 line-clamp-2 text-sm text-neutral-600">{{ $short }}</p>
                                @endif

                                @if ($roomType->default_rate)
                                    <p class="mt-3 text-sm">
                                        {{ __('common.from') }}
                                        <strong>{{ Money::format($roomType->default_rate) }}</strong>
                                        {{ __('common.per_night') }}
                                    </p>
                                @endif
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            <p class="mt-8">
                <a href="{{ Localization::route('rooms.index') }}" class="underline underline-offset-4">
                    {{ __('common.our_rooms') }} →
                </a>
            </p>
        @endif
    </section>

    @if ($events->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 pb-16">
            <h2 class="text-2xl font-semibold tracking-tight">{{ __('events.upcoming') }}</h2>

            <ul class="mt-6 grid gap-6 sm:grid-cols-3">
                @foreach ($events as $event)
                    <li class="rounded-lg border border-neutral-200 p-5">
                        <p class="text-sm font-medium" style="color: var(--doba-primary)">
                            {{ $event->starts_at->translatedFormat('D, j M · H:i') }}
                        </p>
                        <h3 class="mt-1 font-medium">
                            <a href="{{ Localization::route('events.show', ['slug' => $event->slug()]) }}" class="hover:underline">
                                {{ $event->t('title') }}
                            </a>
                        </h3>
                        @if ($excerpt = $event->t('excerpt'))
                            <p class="mt-2 line-clamp-2 text-sm text-neutral-600">{{ $excerpt }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>

            <p class="mt-6">
                <a href="{{ Localization::route('events.index') }}" class="underline underline-offset-4">
                    {{ __('events.all') }} →
                </a>
            </p>
        </section>
    @endif

    @if ($faqs !== [])
        {{-- The visible twin of the FAQPage JSON-LD the controller emits:
             the markup must never describe questions the page doesn't show. --}}
        <section class="mx-auto max-w-3xl px-4 pb-16">
            <h2 class="text-2xl font-semibold tracking-tight">{{ __('common.faq') }}</h2>

            <dl class="mt-6 divide-y divide-neutral-200">
                @foreach ($faqs as $faq)
                    <div class="py-4">
                        <dt class="font-medium">{{ $faq['question'] }}</dt>
                        <dd class="mt-1 text-neutral-600">{{ $faq['answer'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif
@endsection
