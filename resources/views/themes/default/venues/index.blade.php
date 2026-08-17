@extends('layouts.app')

@section('content')
    @php use App\Support\Routing\Localization; @endphp

    <section class="section">
        <div class="wrap">
            <div class="section-head">
                <p class="eyebrow">{{ __('menu.eyebrow') }}</p>
                <h1>{{ __('menu.title') }}</h1>
                <p class="lede">{{ __('menu.intro') }}</p>
            </div>

            <div class="rooms">
                @forelse ($venues as $venue)
                    <article class="room">
                        @if ($cover = $venue->coverImage())
                            <a href="{{ Localization::route('venues.show', ['slug' => $venue->slug()]) }}" class="room-img">
                                <img src="{{ $cover->url() }}" alt="{{ $cover->alt() }}" loading="lazy"
                                     style="width:100%;height:100%;object-fit:cover">
                            </a>
                        @endif

                        <div class="room-body">
                            <h2 style="font-size:1.18rem">
                                <a href="{{ Localization::route('venues.show', ['slug' => $venue->slug()]) }}">
                                    {{ $venue->t('name') }}
                                </a>
                            </h2>

                            <p class="room-meta">
                                <span>{{ __('menu.type_'.$venue->type) }}</span>
                                @if ($venue->seats) <span>{{ __('menu.seats', ['count' => $venue->seats]) }}</span> @endif
                                @if ($venue->isOpenAt())
                                    <span style="color: var(--ok)">{{ __('menu.open_now') }}</span>
                                @endif
                            </p>

                            @if ($tagline = $venue->t('tagline'))
                                <p class="room-desc">{{ $tagline }}</p>
                            @endif

                            <div class="room-foot">
                                <a href="{{ Localization::route('venues.show', ['slug' => $venue->slug()]) }}" class="btn btn--ghost">
                                    {{ __('menu.view_card') }}
                                </a>
                            </div>
                        </div>
                    </article>
                @empty
                    <p class="lede">{{ __('menu.none') }}</p>
                @endforelse
            </div>
        </div>
    </section>
@endsection
