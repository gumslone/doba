@extends('layouts.app')

@section('content')
    @php
        use App\Enums\BookingStatus;
        use App\Support\Routing\Localization;
    @endphp

    <section class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('booking.manage_title') }}</h1>

        @if (session('booking_cancelled'))
            <p class="mt-6 rounded border border-green-200 bg-green-50 p-4 text-green-800" role="status">
                {{ __('booking.cancelled_notice') }}
            </p>
        @endif

        @if (session('booking_error'))
            <p class="mt-6 rounded border border-amber-200 bg-amber-50 p-4 text-amber-900" role="alert">
                {{ session('booking_error') }}
            </p>
        @endif

        <p class="mt-4 text-neutral-600">
            {{ __('booking.status') }}:
            <strong>{{ __('booking.status_'.$booking->status->value) }}</strong>
        </p>

        <div class="mt-8 rounded-lg border border-neutral-200 p-5">
            @include('booking._summary')
        </div>

        <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-neutral-500">{{ __('booking.arrival_time') }}</dt>
                <dd>{{ $booking->arrival_time ?? __('booking.arrival_time_unknown') }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">{{ __('booking.departure_time') }}</dt>
                <dd>
                    {{ $booking->departureTime() }}
                    @if ($booking->hasLateCheckout())
                        <span class="text-green-700">· {{ __('booking.late_checkout_granted') }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($booking->balance_due > 0 && in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true))
            {{-- The number a guest actually wonders about, with the way to
                 make it zero right next to it. Hidden on the manual
                 gateway: there, the desk settles it (§8). --}}
            <div class="mt-6 rounded border border-amber-200 bg-amber-50 p-4">
                <p class="text-amber-900">
                    {{ __('booking.balance_open', ['amount' => \App\Support\Money::format($booking->balance_due)]) }}
                </p>
                @if (\App\Domain\Payments\GatewayRegistry::default()->name() !== 'manual')
                    <form method="POST"
                          action="{{ Localization::route('booking.pay-balance', ['reference' => $booking->reference, 'token' => $token]) }}"
                          class="mt-3">
                        @csrf
                        <button type="submit" class="btn-primary rounded px-5 py-2.5">
                            {{ __('booking.pay_balance_now') }}
                        </button>
                    </form>
                @else
                    <p class="mt-2 text-sm text-amber-800">{{ __('booking.balance_at_desk') }}</p>
                @endif
            </div>
        @endif

        @if (session('booking_notice'))
            <p class="mt-6 rounded border border-green-200 bg-green-50 p-4 text-green-800" role="status">
                {{ session('booking_notice') }}
            </p>
        @endif

        @if (config('doba.features.reviews') && $booking->canBeReviewed())
            {{-- Only after the stay, only once, only through this token:
                 what makes every review on the site a verified one (§5). --}}
            <form method="POST"
                  action="{{ Localization::route('booking.review', ['reference' => $booking->reference, 'token' => $token]) }}"
                  class="mt-6 rounded border border-neutral-200 bg-white p-4">
                @csrf
                <h2 class="font-medium">{{ __('booking.review_title') }}</h2>
                <p class="mt-1 text-sm text-neutral-600">{{ __('booking.review_hint') }}</p>

                <fieldset class="mt-3">
                    <legend class="text-sm font-medium">{{ __('booking.review_rating') }}</legend>
                    <div class="mt-1 flex gap-3">
                        @foreach (range(1, 5) as $rating)
                            <label class="flex items-center gap-1 text-sm">
                                <input type="radio" name="rating" value="{{ $rating }}" required
                                       @checked(old('rating') == $rating)>
                                {{ $rating }}★
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <label for="review-title" class="mt-3 block text-sm font-medium">{{ __('booking.review_headline') }}</label>
                <input id="review-title" name="title" maxlength="120" value="{{ old('title') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">

                <label for="review-body" class="mt-3 block text-sm font-medium">{{ __('booking.review_body') }}</label>
                <textarea id="review-body" name="body" rows="4" required minlength="20" maxlength="4000"
                          class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ old('body') }}</textarea>
                @error('body') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

                <button type="submit" class="btn-primary mt-4 rounded px-5 py-2.5">{{ __('booking.review_submit') }}</button>
            </form>
        @endif

        @if (session('booking_requested'))
            <p class="mt-6 rounded border border-green-200 bg-green-50 p-4 text-green-800" role="status">
                {{ __('booking.late_checkout_requested_notice') }}
            </p>
        @endif

        @if ($booking->hasPendingCheckoutRequest())
            <p class="mt-4 rounded border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700">
                {{ __('booking.late_checkout_pending', ['time' => $booking->requested_checkout_time]) }}
            </p>
        @elseif (! $booking->hasLateCheckout() && in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true))
            <form method="POST"
                  action="{{ Localization::route('booking.late-checkout', ['reference' => $booking->reference, 'token' => $token]) }}"
                  class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-neutral-200 p-5">
                @csrf
                <div>
                    <label for="requested_checkout_time" class="block text-sm font-medium">
                        {{ __('booking.request_late_checkout') }}
                    </label>
                    <input type="time" id="requested_checkout_time" name="requested_checkout_time" required
                           min="{{ config('doba.checkout_until') }}"
                           class="mt-1 rounded border border-neutral-300 px-3 py-2">
                </div>
                <button type="submit" class="rounded border border-neutral-300 px-5 py-2.5 hover:bg-neutral-50">
                    {{ __('booking.request') }}
                </button>
                {{-- Said before they ask, not after we refuse. --}}
                <p class="w-full text-sm text-neutral-500">{{ __('booking.late_checkout_hint') }}</p>
            </form>
        @endif

        @if ($booking->invoice)
            <p class="mt-6">
                <a href="{{ Localization::route('booking.invoice', ['reference' => $booking->reference, 'token' => $token]) }}"
                   class="inline-flex items-center gap-2 rounded border border-neutral-300 px-5 py-2.5 hover:bg-neutral-50">
                    {{ __('booking.download_invoice', ['number' => $booking->invoice->number]) }}
                </a>
            </p>
        @endif

        @if ($booking->status->canTransitionTo(BookingStatus::Cancelled))
            <form method="POST"
                  action="{{ Localization::route('booking.cancel', ['reference' => $booking->reference, 'token' => $token]) }}"
                  onsubmit="return confirm('{{ __('booking.cancel_confirm') }}')"
                  class="mt-8">
                @csrf
                <button type="submit" class="rounded border border-red-300 px-5 py-2.5 text-red-700 hover:bg-red-50">
                    {{ __('booking.cancel') }}
                </button>
            </form>
        @endif
    </section>
@endsection
