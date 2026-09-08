@php
    use App\Support\Money;

    $locale = $booking->locale;
    $hotel = app(\App\Support\Hotel\HotelSettings::class);
@endphp

<x-mail::message>
@php
    $words = [
        'name' => $booking->guest?->first_name,
        'hotel' => $hotel->name,
        'date' => $booking->check_in->translatedFormat('l, j M Y'),
    ];
@endphp
# {{ \App\Support\Mail\Wording::text('pre_arrival_heading', $words, $locale) }}

{{ \App\Support\Mail\Wording::text('pre_arrival_intro', $words, $locale) }}

**{{ __('booking.reference', [], $locale) }}:** {{ $booking->reference }}
**{{ __('mail.check_in_from', [], $locale) }}:** {{ config('doba.checkin_from') }}
@if ($hotel->get('contact.street'))
**{{ __('mail.address', [], $locale) }}:** {{ $hotel->get('contact.street') }}, {{ $hotel->get('contact.postal_code') }} {{ $hotel->get('contact.city') }}
@endif
@if ($hotel->get('contact.phone'))
**{{ __('mail.phone', [], $locale) }}:** {{ $hotel->get('contact.phone') }}
@endif

@if ($booking->balance_due > 0)
{{ __('mail.pre_arrival_balance', ['amount' => Money::format($booking->balance_due, $booking->currency, $locale)], $locale) }}
@endif

@if (! $booking->arrival_time)
{{ __('mail.pre_arrival_time_ask', [], $locale) }}
@endif

<x-mail::button :url="$manageUrl">
{{ __('booking.manage_title', [], $locale) }}
</x-mail::button>

{{ \App\Support\Mail\Wording::text('pre_arrival_outro', $words, $locale) }}

{{ $hotel->name }}
</x-mail::message>
