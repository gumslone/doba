@php
    use App\Support\Routing\Localization;

    $dayNames = [];
    $monday = now()->startOfWeek();

    for ($i = 0; $i < 7; $i++) {
        $dayNames[] = $monday->copy()->addDays($i)->translatedFormat('D');
    }

    $first = $roomTypes->first();

    $config = [
        'roomType' => $first->id,
        'endpoint' => url('/api/calendar'),
        'checkoutUrl' => Localization::route('booking.checkout'),
        'currency' => config('doba.currency'),
        'locale' => Localization::bcp47(app()->getLocale()),
        'maxNights' => (int) config('doba.booking.max_nights'),
        'dayNames' => $dayNames,
        'strings' => [
            'hint' => __('booking.cal_hint'),
            'pick_departure' => __('booking.cal_pick_departure'),
            'cta' => __('booking.cal_cta'),
            'ctd' => __('booking.cal_ctd'),
            'gap' => __('booking.cal_gap'),
            'short' => __('booking.cal_short'),
            'too_long' => __('booking.cal_too_long'),
            'ok' => __('booking.cal_ok'),
            'nights_label' => __('booking.nights_label'),
            'total' => __('booking.total'),
            'avg_night' => __('booking.avg_night'),
            'leg_closed' => __('booking.leg_closed'),
        ],
    ];
@endphp

{{-- Progressive enhancement: without JavaScript the guest still has the
     date fields in the search form and the booking card, and loses only
     the preview. Config travels as a data attribute rather than an inline
     script so the §14 CSP needs no script-src exception. --}}
<div class="cal-shell" data-doba-calendar data-config="{{ json_encode($config, JSON_THROW_ON_ERROR) }}">
    <div class="cal-top">
        <div>
            @if ($roomTypes->count() > 1)
                <div class="eyebrow" style="margin-bottom:6px">{{ __('booking.category') }}</div>
                <select class="control" style="min-width:260px" data-cal-room aria-label="{{ __('booking.category') }}">
                    @foreach ($roomTypes as $roomType)
                        <option value="{{ $roomType->id }}">{{ $roomType->t('name') }}</option>
                    @endforeach
                </select>
            @else
                <div class="eyebrow" style="margin-bottom:0">{{ $first->t('name') }}</div>
            @endif
        </div>

        <div class="cal-nav">
            <button type="button" class="iconbtn" data-cal-prev aria-label="{{ __('booking.cal_prev') }}">
                <svg width="8" height="13" viewBox="0 0 8 13" fill="none" aria-hidden="true">
                    <path d="M7 1L1.5 6.5 7 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </button>
            <button type="button" class="iconbtn" data-cal-next aria-label="{{ __('booking.cal_next') }}">
                <svg width="8" height="13" viewBox="0 0 8 13" fill="none" aria-hidden="true">
                    <path d="M1 1l5.5 5.5L1 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
    </div>

    <div class="months" data-cal-months></div>

    <div class="legend">
        <span><i style="background:var(--paper-2);border:1px solid var(--line)"></i>{{ __('booking.leg_free') }}</span>
        <span><i style="background:repeating-linear-gradient(-45deg,#f6f4ef,#f6f4ef 3px,#efece4 3px,#efece4 6px)"></i>{{ __('booking.leg_closed') }}</span>
        <span><i style="background:var(--doba-primary)"></i>{{ __('booking.leg_selected') }}</span>
        <span><i style="background:#fff;box-shadow:inset 0 -2px 0 var(--danger);border:1px solid var(--line)"></i>{{ __('booking.leg_cta') }}</span>
        <span><i style="background:var(--danger);border-radius:50%;width:9px;height:9px"></i>{{ __('booking.leg_low') }}</span>
    </div>

    <div class="summary">
        <div class="figures" data-cal-figures></div>

        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <span class="msg" data-cal-message>{{ __('booking.cal_hint') }}</span>
            <button type="button" class="btn btn--gold" data-cal-continue disabled>
                {{ __('booking.cal_continue') }}
            </button>
        </div>
    </div>
</div>
