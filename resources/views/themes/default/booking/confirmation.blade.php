@extends('layouts.app')

@section('content')
    @php
        use App\Enums\BookingStatus;
        use App\Support\Routing\Localization;
    @endphp

    <section class="mx-auto max-w-2xl px-4 py-12">
        @if ($booking->status === BookingStatus::Cancelled)
            <h1 class="text-3xl font-semibold tracking-tight">{{ __('booking.cancelled_heading') }}</h1>
        @elseif ($booking->status === BookingStatus::Pending)
            {{-- The return page never confirms anything itself — the webhook
                 is the source of truth (§8). It only reports what the
                 booking currently says. --}}
            <h1 class="text-3xl font-semibold tracking-tight">{{ __('booking.pending_heading') }}</h1>
        @else
            <h1 class="text-3xl font-semibold tracking-tight">{{ __('booking.confirmed_heading') }}</h1>
        @endif

        <p class="mt-3 text-neutral-600">
            {{ __('booking.confirmation_note', ['email' => $booking->guest?->email]) }}
        </p>

        <div class="mt-8 rounded-lg border border-neutral-200 p-5">
            @include('booking._summary')
        </div>

        <p class="mt-6 text-sm text-neutral-500">{{ __('booking.manage_link') }}</p>

        <p class="mt-8">
            <a href="{{ Localization::route('home') }}" class="link-accent underline underline-offset-4">
                {{ $hotel->name }} →
            </a>
        </p>
    </section>
@endsection
