@php
    use App\Support\Money;
    use App\Support\Routing\Localization;

    $url = Localization::route('rooms.show', ['slug' => $roomType->slug()]);
@endphp

<article class="room">
    <div class="room-img">
        <a href="{{ $url }}" tabindex="-1" aria-hidden="true">
            <x-responsive-image :media="$roomType->coverImage()" :eager="$eager ?? false"
                                sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 380px" />
        </a>
    </div>

    <div class="room-body">
        <h3><a href="{{ $url }}" style="text-decoration:none">{{ $roomType->t('name') }}</a></h3>

        <div class="room-meta">
            @if ($roomType->size_sqm)
                <span>▢ {{ __('common.sqm', ['size' => $roomType->size_sqm]) }}</span>
            @endif
            <span>◍ {{ __('common.guests', ['count' => $roomType->max_occupancy]) }}</span>
            @if ($roomType->isApartment() && $roomType->bedrooms)
                <span>⌂ {{ trans_choice('common.bedrooms', $roomType->bedrooms) }}</span>
            @elseif ($roomType->bed_setup)
                <span>⌾ {{ $roomType->bed_setup }}</span>
            @endif
        </div>

        @if ($short = $roomType->t('short_description'))
            <p class="room-desc">{{ $short }}</p>
        @endif

        @php $tags = array_slice($roomType->amenityNames(), 0, 3); @endphp
        @if ($tags !== [])
            <div class="tags">
                @foreach ($tags as $tag)
                    <span class="tag">{{ $tag }}</span>
                @endforeach
            </div>
        @endif

        <div class="room-foot">
            @if ($roomType->default_rate)
                <div>
                    <span class="price-from">{{ __('common.from') }}</span>
                    <span class="price">{{ Money::format($roomType->default_rate) }}</span>
                    <small>{{ __('common.per_night') }}</small>
                    @if ($roomType->cleaning_fee > 0)
                        <small style="display:block">+ {{ Money::format($roomType->cleaning_fee) }} {{ __('common.cleaning_fee') }}</small>
                    @endif
                </div>
            @else
                <div></div>
            @endif

            <a class="btn btn--ghost" href="{{ $url }}">{{ __('common.view_room') }}</a>
        </div>
    </div>
</article>
