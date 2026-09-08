@php
    $locale = $booking->locale;
    $hotel = app(\App\Support\Hotel\HotelSettings::class);
@endphp

<x-mail::message>
@php $words = ['name' => $booking->guest?->first_name, 'hotel' => $hotel->name]; @endphp
# {{ \App\Support\Mail\Wording::text('post_stay_heading', $words, $locale) }}

{{ \App\Support\Mail\Wording::text('post_stay_intro', $words, $locale) }}

@if ($booking->invoice)
{{ __('mail.post_stay_invoice', [], $locale) }}
@endif

@if (config('doba.features.reviews') && $booking->canBeReviewed())
{{ __('mail.post_stay_review_ask', [], $locale) }}
@endif

<x-mail::button :url="$manageUrl">
{{ __('booking.manage_title', [], $locale) }}
</x-mail::button>

{{ \App\Support\Mail\Wording::text('post_stay_outro', $words, $locale) }}

{{ $hotel->name }}
</x-mail::message>
