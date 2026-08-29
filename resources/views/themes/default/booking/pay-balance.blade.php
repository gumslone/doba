@extends('layouts.app')

@section('content')
    @php use App\Support\Money; use App\Support\Routing\Localization; @endphp

    <section class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('booking.balance_title') }}</h1>

        <p class="mt-4 text-neutral-600">
            {{ __('booking.balance_intro', ['reference' => $booking->reference]) }}
        </p>

        <div class="mt-8 rounded-lg border border-neutral-200 p-5">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-neutral-500">{{ __('booking.total') }}</dt>
                    <dd>{{ Money::format($booking->total) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">{{ __('booking.paid_so_far') }}</dt>
                    <dd>{{ Money::format($booking->paid_amount) }}</dd>
                </div>
                <div class="flex justify-between border-t border-neutral-200 pt-2 text-base font-semibold">
                    <dt>{{ __('booking.balance_due') }}</dt>
                    <dd>{{ Money::format($payment->amount) }}</dd>
                </div>
            </dl>
        </div>

        <div class="mt-8">
            @if ($approvalUrl)
                {{-- PayPal / LiqPay / Coinbase: approved on the provider's
                     page; the webhook, never this redirect, credits it. --}}
                <a href="{{ $approvalUrl }}" rel="noopener"
                   class="btn-primary inline-block rounded px-6 py-3 text-lg">
                    {{ __('booking.pay_redirect') }}
                </a>
            @else
                {{-- Stripe Elements, same SAQ-A boundary as the deposit:
                     card data never touches this server (§8). --}}
                <div id="payment-element"
                     data-client-secret="{{ $payment->payload['client_secret'] ?? '' }}"
                     data-publishable-key="{{ config('services.stripe.key') }}"
                     data-return-url="{{ Localization::route('booking.manage', ['reference' => $booking->reference, 'token' => $token]) }}"></div>
            @endif
        </div>

        <p class="mt-6 text-sm text-neutral-500">
            <a class="underline" href="{{ Localization::route('booking.manage', ['reference' => $booking->reference, 'token' => $token]) }}">
                {{ __('booking.back_to_manage') }}
            </a>
        </p>
    </section>
@endsection
