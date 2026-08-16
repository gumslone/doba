@extends('layouts.app')

@section('content')
    <section class="section">
        <div class="wrap">
            <div class="section-head">
                <div class="eyebrow">{{ __('common.rooms') }}</div>
                <h2 style="font-size:clamp(2rem,4vw,3rem)">{{ __('seo.rooms.title') }}</h2>
                <p class="lede">{{ __('common.rooms_lede') }}</p>
            </div>

            @if ($roomTypes->isEmpty())
                <p class="lede">{{ __('common.no_rooms_yet') }}</p>
            @else
                <div class="rooms">
                    @foreach ($roomTypes as $roomType)
                        @continue (! $roomType->slug())
                        @include('rooms._card', ['roomType' => $roomType, 'eager' => $loop->first])
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
