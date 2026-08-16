@extends('layouts.app')

@section('content')
    @php use App\Support\Money; @endphp

    <section class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('booking.pay_title') }}</h1>

        @if ($holdExpiresAt)
            <p class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                {{ __('booking.hold_notice', [
                    'time' => \Illuminate\Support\Carbon::parse($holdExpiresAt)->timezone(config('doba.timezone'))->format('H:i'),
                ]) }}
            </p>
        @endif

        <div class="mt-8 rounded-lg border border-neutral-200 p-5">
            @include('booking._summary')
        </div>

        <div class="mt-8">
            @if ($gateway === 'manual')
                {{-- Bank transfer / pay on arrival: the booking is already
                     confirmed, there is nothing to collect online (§8). --}}
                <p class="rounded border border-green-200 bg-green-50 p-4 text-green-800">
                    {{ __('booking.pay_manual') }}
                </p>
            @elseif ($approvalUrl)
                {{-- PayPal / LiqPay / Coinbase: the guest approves on the
                     provider's own page, and the webhook — not this
                     redirect — is what confirms the booking. --}}
                <a href="{{ $approvalUrl }}" rel="noopener"
                   class="btn-primary inline-block rounded px-6 py-3 text-lg">
                    {{ __('booking.pay_redirect') }}
                </a>
            @else
                {{-- Stripe: Elements collects the card on this domain, so no
                     card data ever reaches this server (SAQ-A, §8). The
                     element mounts from resources/js when the publishable
                     key is configured. --}}
                <div id="payment-element"
                     data-client-secret="{{ $payment?->payload['client_secret'] ?? '' }}"
                     data-publishable-key="{{ config('services.stripe.key') }}"
                     data-return-url="{{ \App\Support\Routing\Localization::route('booking.confirmation', ['reference' => $booking->reference]) }}"></div>

                <p class="mt-4 text-sm text-neutral-500">
                    {{ __('booking.deposit') }}: <strong>{{ Money::format($booking->deposit_due) }}</strong>
                </p>
            @endif
        </div>
    </section>
@endsection
