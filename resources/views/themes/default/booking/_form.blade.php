{{-- The date/guest search form, reused on the home page and the results
     page. GET, so a search is a shareable, back-button-safe URL — and it
     works with JavaScript disabled. --}}
@php
    use App\Support\Routing\Localization;

    $today = now(config('doba.timezone'))->toDateString();
    $max = now(config('doba.timezone'))->addDays((int) config('doba.booking.booking_window_days'))->toDateString();

    // The form renders both with a stay (results page) and without (home
    // page), so every field falls back rather than assuming one. Note the
    // offsets are guarded before ?-> — the null-safe operator guards the
    // method call, not the array access in front of it.
    $stay = $stay ?? [];
    $checkInValue = ($stay['check_in'] ?? null)?->toDateString() ?? '';
    $checkOutValue = ($stay['check_out'] ?? null)?->toDateString() ?? '';
@endphp

<form method="GET" action="{{ Localization::route('booking.search') }}" class="bookbar-card">
    <div class="field">
        <label for="check_in">{{ __('booking.check_in') }}</label>
        <input class="control" type="date" id="check_in" name="check_in" required
               min="{{ $today }}" max="{{ $max }}" value="{{ $checkInValue }}">
    </div>
    <div class="field">
        <label for="check_out">{{ __('booking.check_out') }}</label>
        <input class="control" type="date" id="check_out" name="check_out" required
               min="{{ $today }}" max="{{ $max }}" value="{{ $checkOutValue }}">
    </div>
    <div class="field">
        <label for="adults">{{ __('booking.guests') }}</label>
        <div style="display:flex;gap:8px">
            <input class="control" type="number" id="adults" name="adults" min="1" max="20"
                   value="{{ $stay['adults'] ?? 2 }}" aria-label="{{ __('booking.adults') }}">
            <input class="control" type="number" id="children" name="children" min="0" max="20"
                   value="{{ $stay['children'] ?? 0 }}" aria-label="{{ __('booking.children') }}">
        </div>
    </div>
    <div class="field">
        <button type="submit" class="btn btn--primary btn--block" style="min-height:46px">
            {{ __('booking.search') }}
        </button>
    </div>
</form>
