@extends('layouts.app')

@section('content')
    @php
        use App\Support\Media\ResponsiveImage;
        use App\Support\Money;
        use App\Support\Routing\Localization;
    @endphp

    <section class="hero">
        <div class="hero-bg">
            @if ($hero)
                {{-- The LCP element on the whole site: eager and high
                     priority. Lazy-loading it is a self-inflicted ranking
                     loss (§11). --}}
                <img {!! collect(ResponsiveImage::attributes($hero, '100vw', eager: true))
                    ->map(fn ($v, $k) => $k.'="'.e($v).'"')->implode(' ') !!}>
            @else
                @include('partials.hero-fallback')
            @endif
        </div>
        <div class="hero-scrim"></div>

        <div class="hero-inner"><div class="wrap">
            @if ($stars = (int) $hotel->get('general.star_rating'))
                <span class="hero-badge">
                    <span class="stars">{{ str_repeat('★', min(5, $stars)) }}</span>
                    @if ($since = $hotel->get('general.since'))
                        <span>{{ __('common.family_run_since', ['year' => $since]) }}</span>
                    @endif
                </span>
            @endif

            {{-- Exactly one h1, carrying the words the page should rank for.
                 The hotel name alone ranks for the hotel name — traffic the
                 hotel already had. --}}
            <h1>{{ $hotel->get('seo.h1') ?: $hotel->name }}</h1>

            @if ($tagline = $hotel->get('general.tagline'))
                <p class="hero-sub">{{ $tagline }}</p>
            @endif
        </div></div>
    </section>

    <div class="bookbar"><div class="wrap">
        @include('booking._form', ['stay' => null])

        <p class="bookbar-note">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M8 1l2.2 4.4 4.8.7-3.5 3.4.8 4.8L8 12l-4.3 2.3.8-4.8L1 6.1l4.8-.7z" fill="var(--doba-accent)"/>
            </svg>
            <span>{{ __('seo.direct_booking_note') }}</span>
        </p>
    </div></div>

    @if ($usps !== [])
        <section class="section">
            <div class="wrap">
                <div class="usps">
                    @foreach ($usps as $usp)
                        <div class="usp">
                            @include('partials.icon', ['name' => $usp['icon'] ?? 'check'])
                            <b>{{ $usp['title'] }}</b>
                            <span>{{ $usp['subtitle'] ?? '' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="section section--tint" id="rooms">
        <div class="wrap">
            <div class="section-head">
                <div class="eyebrow">{{ __('common.rooms') }}</div>
                <h2>{{ __('common.our_rooms') }}</h2>
                <p class="lede">{{ __('common.rooms_lede') }}</p>
            </div>

            @if ($roomTypes->isEmpty())
                <p class="lede">{{ __('common.no_rooms_yet') }}</p>
            @else
                <div class="rooms">
                    @foreach ($roomTypes as $roomType)
                        @continue (! $roomType->slug())
                        @include('rooms._card', ['roomType' => $roomType, 'eager' => false])
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    @if ($roomTypes->isNotEmpty())
        <section class="section" id="calendar">
            <div class="wrap">
                <div class="section-head">
                    <div class="eyebrow">{{ __('booking.availability') }}</div>
                    <h2>{{ __('booking.calendar_title') }}</h2>
                    <p class="lede">{{ __('booking.calendar_lede') }}</p>
                </div>

                @include('booking._calendar', ['roomTypes' => $roomTypes])
            </div>
        </section>
    @endif

    @if ($galleryPhotos->isNotEmpty())
        <section class="section section--tint">
            <div class="wrap">
                <div class="section-head">
                    <div class="eyebrow">{{ __('common.gallery') }}</div>
                    <h2>{{ __('common.gallery_title') }}</h2>
                </div>

                <div class="rooms">
                    @foreach ($galleryPhotos as $photo)
                        <div style="border-radius:var(--radius);overflow:hidden">
                            <x-responsive-image :media="$photo" sizes="(max-width: 640px) 100vw, 380px"
                                                class="aspect-[4/3] w-full object-cover" />
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($reviews->isNotEmpty())
        {{-- Every one of these belongs to a stay that happened here —
             that is the whole difference between this block and a star
             widget (§5). --}}
        <section class="section section--tint">
            <div class="container">
                <div class="section-head">
                    <div class="eyebrow">{{ __('common.reviews_eyebrow') }}</div>
                    <h2>{{ __('common.reviews_title') }}</h2>
                </div>
                <div class="grid grid--3">
                    @foreach ($reviews as $review)
                        <article class="card stack">
                            <div aria-label="{{ __('common.review_rating', ['rating' => $review->rating]) }}">
                                <span aria-hidden="true">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</span>
                            </div>
                            @if ($review->title)
                                <h3>{{ $review->title }}</h3>
                            @endif
                            <p>{{ $review->body }}</p>
                            <p class="text-muted">
                                {{ $review->guest?->first_name }} ·
                                {{ $review->published_at?->translatedFormat('F Y') }}
                                · {{ __('common.verified_stay') }}
                            </p>
                            @if ($review->hotel_response)
                                <p class="text-muted">{{ __('common.hotel_replied') }}: {{ $review->hotel_response }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($events->isNotEmpty())
        <section class="section">
            <div class="wrap">
                <div class="section-head">
                    <div class="eyebrow">{{ __('events.title') }}</div>
                    <h2>{{ __('events.upcoming') }}</h2>
                </div>

                <div class="rooms">
                    @foreach ($events as $event)
                        <article class="room">
                            <div class="room-body">
                                <div class="eyebrow" style="margin-bottom:.3rem">
                                    {{ $event->starts_at->translatedFormat('D, j M · H:i') }}
                                </div>
                                <h3>
                                    <a href="{{ Localization::route('events.show', ['slug' => $event->slug()]) }}"
                                       style="text-decoration:none">{{ $event->t('title') }}</a>
                                </h3>
                                @if ($excerpt = $event->t('excerpt'))
                                    <p class="room-desc">{{ $excerpt }}</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                <p style="margin-top:26px">
                    <a class="btn btn--ghost" href="{{ Localization::route('events.index') }}">{{ __('events.all') }}</a>
                </p>
            </div>
        </section>
    @endif

    @if ($amenities !== [])
        <section class="section section--tint">
            <div class="wrap">
                <div class="section-head">
                    <div class="eyebrow">{{ __('extras.includes') }}</div>
                    <h2>{{ __('common.included_in_rate') }}</h2>
                </div>

                <div class="amen">
                    @foreach ($amenities as $amenity)
                        <div>
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M2 8.6l3.6 3.6L14 3.8" stroke="currentColor" stroke-width="1.8"
                                      stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            {{ $amenity }}
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($faqs !== [])
        {{-- The visible twin of the FAQPage JSON-LD the controller emits:
             the markup must never describe questions the page doesn't show. --}}
        <section class="section">
            <div class="wrap">
                <div class="section-head" style="text-align:center;margin-left:auto;margin-right:auto">
                    <div class="eyebrow">{{ __('common.faq') }}</div>
                    <h2>{{ __('common.good_to_know') }}</h2>
                </div>

                <div class="faq">
                    @foreach ($faqs as $index => $faq)
                        <details @if ($index === 0) open @endif>
                            <summary>{{ $faq['question'] }}</summary>
                            <div class="ans">{{ $faq['answer'] }}</div>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
