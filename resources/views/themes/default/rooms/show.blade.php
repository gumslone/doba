@extends('layouts.app', ['hideBreadcrumbs' => true])

@section('content')
    @php
        use App\Support\Money;
        use App\Support\Routing\Localization;

        $photos = $roomType->media;
        $cover = $roomType->coverImage();
        $sidePhotos = $photos->reject(fn ($p) => $p->is($cover))->take(3);
        $inclusions = $roomType->inclusionsByCategory();
        $extras = $roomType->availableExtras();
    @endphp

    <div class="wrap">
        {{-- Visible twin of the BreadcrumbList in JSON-LD (§11). --}}
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="{{ Localization::route('home') }}">{{ $hotel->name }}</a><span aria-hidden="true">›</span>
            <a href="{{ Localization::route('rooms.index') }}">{{ __('common.rooms') }}</a><span aria-hidden="true">›</span>
            <span style="color:var(--ink)" aria-current="page">{{ $roomType->t('name') }}</span>
        </nav>

        @if ($cover)
            <div class="gal">
                <div class="gal-main">
                    <x-responsive-image :media="$cover" :eager="true" sizes="(max-width: 980px) 100vw, 860px" />
                    @if ($roomType->size_sqm)
                        <span class="gal-badge">{{ __('common.sqm', ['size' => $roomType->size_sqm]) }}</span>
                    @endif
                </div>

                @if ($sidePhotos->isNotEmpty())
                    <div class="gal-side">
                        @foreach ($sidePhotos as $photo)
                            <div><x-responsive-image :media="$photo" sizes="300px" /></div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="cols">
            <main>
                <div>
                    @if ($bed = $roomType->bed_setup)
                        <div class="eyebrow">{{ $bed }}</div>
                    @endif
                    <h1 style="font-size:clamp(2rem,4vw,3rem)">{{ $roomType->t('name') }}</h1>

                    <div class="facts">
                        @if ($roomType->size_sqm)
                            <span>
                                <svg width="17" height="17" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="M2.5 2.5h15v15h-15z" stroke="currentColor" stroke-width="1.3"/>
                                    <path d="M6 6h8v8H6z" stroke="currentColor" stroke-width="1" stroke-dasharray="2 2"/>
                                </svg>
                                {{ __('common.sqm', ['size' => $roomType->size_sqm]) }}
                            </span>
                        @endif
                        <span>
                            <svg width="17" height="17" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <circle cx="10" cy="6.5" r="3.2" stroke="currentColor" stroke-width="1.3"/>
                                <path d="M3.5 17c0-3.6 2.9-5.6 6.5-5.6s6.5 2 6.5 5.6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                            </svg>
                            {{ __('common.guests', ['count' => $roomType->max_occupancy]) }}
                        </span>
                        @if ($bed)
                            <span>
                                <svg width="17" height="17" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="M2 14V7.5C2 6.7 2.7 6 3.5 6h13c.8 0 1.5.7 1.5 1.5V14M2 14h16M2 14v3M18 14v3M5.5 6V4.5h9V6"
                                          stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                {{ $bed }}
                            </span>
                        @endif
                    </div>
                </div>

                @if ($description = $roomType->t('description'))
                    <section class="block">
                        <div class="prose lede" style="max-width:70ch">{!! $description !!}</div>
                    </section>
                @endif

                @if ($inclusions !== [])
                    <section class="block">
                        <h2>{{ __('extras.includes') }}</h2>
                        <div style="display:grid;gap:26px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                            @foreach ($inclusions as $category => $amenities)
                                <div>
                                    <h4 style="font-family:var(--doba-font-body);font-size:.7rem;letter-spacing:.13em;text-transform:uppercase;color:var(--ink-faint);font-weight:600;margin-bottom:10px">
                                        {{ __('extras.category_'.$category) }}
                                    </h4>
                                    <ul style="list-style:none;margin:0;padding:0;display:grid;gap:7px;font-size:.9rem;color:var(--ink-soft)">
                                        @foreach ($amenities as $amenity)
                                            <li style="display:flex;gap:9px;align-items:center">
                                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="color:var(--doba-moss);flex:none" aria-hidden="true">
                                                    <path d="M2 8.6l3.6 3.6L14 3.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                                {{ $amenity->t('name') }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="block">
                    <h2>{{ __('common.occupancy') }}</h2>
                    <table class="tbl">
                        <tbody>
                            <tr>
                                <th scope="row">{{ __('common.base_occupancy') }}</th>
                                <td>{{ __('common.guests', ['count' => $roomType->base_occupancy]) }}</td>
                            </tr>
                            <tr>
                                <th scope="row">{{ __('common.max_occupancy') }}</th>
                                <td>{{ __('common.guests', ['count' => $roomType->max_occupancy]) }}</td>
                            </tr>
                            @if ($roomType->max_children > 0)
                                <tr>
                                    <th scope="row">{{ __('booking.children') }}</th>
                                    <td>{{ __('common.up_to', ['count' => $roomType->max_children]) }}</td>
                                </tr>
                            @endif
                            @if ($roomType->extra_adult_price > 0)
                                <tr>
                                    <th scope="row">{{ __('common.extra_adult') }}</th>
                                    <td>{{ Money::format($roomType->extra_adult_price) }} {{ __('common.per_night') }}</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </section>

                @if ($extras->isNotEmpty())
                    <section class="block">
                        <h2>{{ __('extras.title') }}</h2>
                        <p class="lede" style="margin-bottom:16px">{{ __('extras.intro') }}</p>

                        <div class="plans">
                            @foreach ($extras as $extra)
                                <div class="plan" style="grid-template-columns:1fr auto">
                                    <div>
                                        <h3>{{ $extra->t('name') }}</h3>
                                        @if ($description = $extra->t('description'))
                                            <p class="terms">{{ $description }}</p>
                                        @endif
                                    </div>
                                    <div class="amt">
                                        @if ($extra->is_included)
                                            <b style="font-size:1rem;color:var(--ok)">{{ __('extras.included') }}</b>
                                        @else
                                            <b>{{ Money::format($extra->price) }}</b>
                                            <small>{{ __($extra->applies_per->label()) }}</small>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="block" id="cal">
                    <h2>{{ __('booking.availability') }}</h2>
                    <p class="lede" style="margin-bottom:16px">{{ __('booking.calendar_lede') }}</p>
                    @include('booking._calendar', ['roomTypes' => collect([$roomType])])
                </section>

                <section class="block">
                    <h2>{{ __('common.good_to_know') }}</h2>
                    <div class="pol">
                        <div>
                            <h4>{{ __('common.check_in_label') }}</h4>
                            <p>{{ __('common.check_in_from', ['time' => config('doba.checkin_from')]) }}</p>
                        </div>
                        <div>
                            <h4>{{ __('common.check_out_label') }}</h4>
                            <p>{{ __('common.check_out_until', ['time' => config('doba.checkout_until')]) }}</p>
                        </div>
                        @if ($policy = $hotel->get('policy.cancellation'))
                            <div>
                                <h4>{{ __('common.cancellation') }}</h4>
                                <p>{{ $policy }}</p>
                            </div>
                        @endif
                    </div>
                </section>

                @if ($similar->isNotEmpty())
                    <section class="block">
                        <h2>{{ __('common.similar_rooms') }}</h2>
                        <div class="sim">
                            @foreach ($similar as $other)
                                <a href="{{ Localization::route('rooms.show', ['slug' => $other->slug()]) }}"
                                   class="room" style="text-decoration:none">
                                    <div class="room-img">
                                        <x-responsive-image :media="$other->coverImage()" sizes="260px" />
                                    </div>
                                    <div class="room-body" style="gap:6px">
                                        <h3 style="font-size:1.05rem">{{ $other->t('name') }}</h3>
                                        @if ($other->default_rate)
                                            <p style="font-size:.85rem;color:var(--ink-faint);margin:0">
                                                {{ __('common.from') }} <strong style="color:var(--ink)">{{ Money::format($other->default_rate) }}</strong>
                                            </p>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </main>

            <aside class="side">
                @include('rooms._booking-card', ['roomType' => $roomType])
            </aside>
        </div>
    </div>
@endsection
