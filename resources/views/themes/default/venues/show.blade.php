@extends('layouts.app')

@section('content')
    @php
        use App\Enums\Allergen;
        use App\Models\Venue;
        use App\Support\Money;

        // Only the allergens this card actually declares get a key at the
        // foot of the page: printing all fourteen when three are in use
        // teaches a guest to ignore the list.
        $usedAllergens = $sections
            ->flatMap(fn ($section) => $section->dishes->where('is_available', true))
            ->flatMap(fn ($dish) => $dish->allergenCases())
            ->unique->value
            ->sortBy(fn (Allergen $a) => $a->number());
    @endphp

    <section class="section">
        <div class="wrap">
            <div class="section-head">
                <p class="eyebrow">{{ __('menu.type_'.$venue->type) }}</p>
                <h1>{{ $venue->t('name') }}</h1>
                @if ($tagline = $venue->t('tagline'))
                    <p class="lede">{{ $tagline }}</p>
                @endif
            </div>

            <div class="venue-head">
                <div>
                    @if ($description = $venue->t('description'))
                        <p>{{ $description }}</p>
                    @endif

                    <p class="venue-contact">
                        @if ($venue->phone)
                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $venue->phone) }}">{{ $venue->phone }}</a>
                        @endif
                        @if ($venue->reservations) · {{ __('menu.reservations') }} @endif
                        @if ($venue->price_range) · {{ $venue->price_range }} @endif
                    </p>
                </div>

                @if ($venue->opening_hours)
                    <table class="hours">
                        <caption>{{ __('menu.opening_hours') }}</caption>
                        <tbody>
                            @foreach (Venue::DAYS as $day)
                                @php $periods = $venue->opening_hours[$day] ?? []; @endphp
                                <tr @class(['is-today' => $day === Venue::DAYS[now()->dayOfWeekIso - 1]])>
                                    <th scope="row">{{ __('menu.day_'.$day) }}</th>
                                    <td>
                                        @forelse ($periods as $period)
                                            <span>{{ $period[0] }}–{{ $period[1] }}</span>@if (! $loop->last), @endif
                                        @empty
                                            <span class="closed">{{ __('menu.closed') }}</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </section>

    <section class="section section--tint">
        <div class="wrap">
            @forelse ($sections as $section)
                <div class="card-section">
                    <h2>{{ $section->t('name') }}</h2>
                    @if ($note = $section->t('description'))
                        <p class="lede">{{ $note }}</p>
                    @endif

                    <ul class="dishes">
                        @foreach ($section->dishes->where('is_available', true) as $dish)
                            <li class="dish">
                                <div>
                                    <p class="dish-name">
                                        {{ $dish->t('name') }}
                                        @if ($dish->is_signature)
                                            <span class="tag">{{ __('menu.signature') }}</span>
                                        @endif
                                        @foreach ($dish->dietCases() as $diet)
                                            <span class="tag">{{ __($diet->label()) }}</span>
                                        @endforeach
                                    </p>

                                    @if ($description = $dish->t('description'))
                                        <p class="dish-desc">{{ $description }}</p>
                                    @endif

                                    @if ($dish->allergenCases()->isNotEmpty())
                                        <p class="dish-allergens">
                                            {{ __('menu.contains') }}
                                            {{ $dish->allergenCases()->map(fn (Allergen $a) => $a->number())->join(', ') }}
                                        </p>
                                    @endif
                                </div>

                                <p class="dish-price">
                                    @if ($dish->price === null)
                                        {{ __('menu.market_price') }}
                                    @else
                                        {{ Money::format($dish->price) }}
                                        @if ($dish->unit) <span class="dish-unit">/ {{ $dish->unit }}</span> @endif
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="lede">{{ __('menu.card_empty') }}</p>
            @endforelse

            @if ($usedAllergens->isNotEmpty())
                {{-- EU 1169/2011: the declarable allergens, spelled out. The
                     numbers beside each dish are meaningless without it. --}}
                <div class="allergen-key">
                    <h3>{{ __('menu.allergen_key') }}</h3>
                    <ul>
                        @foreach ($usedAllergens as $allergen)
                            <li><strong>{{ $allergen->number() }}</strong> {{ __($allergen->label()) }}</li>
                        @endforeach
                    </ul>
                    <p>{{ __('menu.allergen_note') }}</p>
                </div>
            @endif
        </div>
    </section>
@endsection
