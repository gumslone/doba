@php
    $locale = $booking->locale;
    $hotel = app(\App\Support\Hotel\HotelSettings::class);
@endphp

<x-mail::message>
# {{ __('mail.post_stay_heading', ['name' => $booking->guest?->first_name], $locale) }}

{{ __('mail.post_stay_intro', ['hotel' => $hotel->name], $locale) }}

@if ($booking->invoice)
{{ __('mail.post_stay_invoice', [], $locale) }}
@endif

<x-mail::button :url="$manageUrl">
{{ __('booking.manage_title', [], $locale) }}
</x-mail::button>

{{ __('mail.post_stay_outro', [], $locale) }}

{{ $hotel->name }}
</x-mail::message>
