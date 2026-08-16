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
