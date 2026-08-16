{{-- The date/guest search form, reused on the home page and the results
     page. GET, so a search is a shareable, back-button-safe URL. --}}
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

<form method="GET" action="{{ Localization::route('booking.search') }}"
      class="grid gap-3 sm:grid-cols-5 sm:items-end">
    <div>
        <label for="check_in" class="block text-sm font-medium">{{ __('booking.check_in') }}</label>
        <input type="date" id="check_in" name="check_in" required
               min="{{ $today }}" max="{{ $max }}"
               value="{{ $checkInValue }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
    <div>
        <label for="check_out" class="block text-sm font-medium">{{ __('booking.check_out') }}</label>
        <input type="date" id="check_out" name="check_out" required
               min="{{ $today }}" max="{{ $max }}"
               value="{{ $checkOutValue }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
    <div>
        <label for="adults" class="block text-sm font-medium">{{ __('booking.adults') }}</label>
        <input type="number" id="adults" name="adults" min="1" max="20" value="{{ $stay['adults'] ?? 2 }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
    <div>
        <label for="children" class="block text-sm font-medium">{{ __('booking.children') }}</label>
        <input type="number" id="children" name="children" min="0" max="20" value="{{ $stay['children'] ?? 0 }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
    <button type="submit" class="btn-primary rounded px-5 py-2.5">{{ __('booking.search') }}</button>
</form>
