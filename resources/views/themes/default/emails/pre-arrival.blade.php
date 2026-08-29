@php
    use App\Support\Money;

    $locale = $booking->locale;
    $hotel = app(\App\Support\Hotel\HotelSettings::class);
@endphp

<x-mail::message>
# {{ __('mail.pre_arrival_heading', ['name' => $booking->guest?->first_name], $locale) }}

{{ __('mail.pre_arrival_intro', [
    'hotel' => $hotel->name,
    'date' => $booking->check_in->translatedFormat('l, j M Y'),
], $locale) }}

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

{{ __('mail.pre_arrival_outro', [], $locale) }}

{{ $hotel->name }}
</x-mail::message>
