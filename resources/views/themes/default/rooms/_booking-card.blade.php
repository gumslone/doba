@php
    use App\Support\Money;
    use App\Support\Routing\Localization;

    $today = now(config('doba.timezone'))->toDateString();
    $max = now(config('doba.timezone'))->addDays((int) config('doba.booking.booking_window_days'))->toDateString();
@endphp

{{-- The sticky booking card. A plain GET form to the checkout route, so it
     works without JavaScript; the server re-validates the dates and sends
     the guest back to search if the room has gone. --}}
<form class="bcard" method="GET" action="{{ Localization::route('booking.checkout') }}">
    <input type="hidden" name="room_type" value="{{ $roomType->id }}">

    <div class="bcard-head">
        <div>
            @if ($roomType->default_rate)
                <span class="from">{{ __('common.from') }}</span>
                <span class="amount">{{ Money::format($roomType->default_rate) }}</span>
                <span class="unit">{{ __('common.per_night') }}</span>
            @else
                <span class="amount">{{ $roomType->t('name') }}</span>
            @endif
        </div>
    </div>

    <div class="bcard-body">
        <div class="dates">
            <div>
                <label class="label" for="bc-in">{{ __('booking.check_in') }}</label>
                <input class="input" type="date" id="bc-in" name="check_in" required
                       min="{{ $today }}" max="{{ $max }}" value="{{ request('check_in') }}">
            </div>
            <div>
                <label class="label" for="bc-out">{{ __('booking.check_out') }}</label>
                <input class="input" type="date" id="bc-out" name="check_out" required
                       min="{{ $today }}" max="{{ $max }}" value="{{ request('check_out') }}">
            </div>
        </div>

        <div class="dates">
            <div>
                <label class="label" for="bc-adults">{{ __('booking.adults') }}</label>
                <input class="input" type="number" id="bc-adults" name="adults"
                       min="1" max="{{ $roomType->max_adults ?: $roomType->max_occupancy }}"
                       value="{{ request('adults', min(2, $roomType->max_occupancy)) }}">
            </div>
            <div>
                <label class="label" for="bc-children">{{ __('booking.children') }}</label>
                <input class="input" type="number" id="bc-children" name="children"
                       min="0" max="{{ $roomType->max_children }}"
                       value="{{ request('children', 0) }}"
                       @disabled($roomType->max_children === 0)>
            </div>
        </div>

        <button type="submit" class="btn btn--gold btn--block" style="margin-top:18px">
            {{ __('booking.book_now') }}
        </button>

        <p class="hint" style="margin-top:12px">{{ __('booking.card_hint') }}</p>

        <div class="trust">
            <div>
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M2 8.6l3.6 3.6L14 3.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>{{ __('seo.direct_booking_note') }}</span>
            </div>
            <div>
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M2 8.6l3.6 3.6L14 3.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>{{ __('booking.trust_no_fee') }}</span>
            </div>
            <div>
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M2 8.6l3.6 3.6L14 3.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>{{ __('booking.trust_hold', ['minutes' => config('doba.booking.hold_minutes')]) }}</span>
            </div>
        </div>
    </div>
</form>
