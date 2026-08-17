{{--
    A pending late-checkout request, wherever the booking appears.

    Shown in the in-house list as well as the departure list: a guest
    asks the day before as often as the morning of, and a request that
    only surfaces on the departure morning is one the desk answers with
    the guest already standing there.
--}}
@if ($booking->hasPendingCheckoutRequest())
    <form method="POST" action="/admin/front-desk/{{ $booking->id }}/departure-time"
          class="mt-3 flex flex-wrap items-center gap-2 rounded bg-amber-50 p-2 text-xs">
        @csrf
        <span class="text-amber-900">
            {{ __('admin.late_checkout_requested', ['time' => $booking->requested_checkout_time]) }}
        </span>
        <input type="time" name="checkout_time" value="{{ $booking->requested_checkout_time }}"
               class="rounded border border-amber-300 px-2 py-1">
        <button name="decision" value="grant"
                class="rounded bg-neutral-900 px-2.5 py-1 text-white">{{ __('admin.grant') }}</button>
        <button name="decision" value="decline"
                class="rounded border border-neutral-300 bg-white px-2.5 py-1">{{ __('admin.decline') }}</button>
    </form>
@endif
