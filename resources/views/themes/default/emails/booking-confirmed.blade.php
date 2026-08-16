@php
    use App\Support\Money;

    $locale = $booking->locale;
@endphp

<x-mail::message>
# {{ __('booking.confirmed_heading', [], $locale) }}

{{ __('mail.booking_intro', ['name' => $booking->guest?->first_name], $locale) }}

**{{ __('booking.reference', [], $locale) }}:** {{ $booking->reference }}
**{{ __('booking.dates', [], $locale) }}:** {{ $booking->check_in->translatedFormat('j M Y') }} – {{ $booking->check_out->translatedFormat('j M Y') }}
**{{ __('booking.guests', [], $locale) }}:** {{ $booking->adults + $booking->children }}

@foreach ($booking->rooms as $room)
- {{ $room->roomType?->t('name', $locale) }} — {{ Money::format($room->price_total, $booking->currency, $locale) }}
@endforeach
@foreach ($booking->extras as $bookingExtra)
- {{ $bookingExtra->extra?->t('name', $locale) }}@if ($bookingExtra->quantity > 1) × {{ $bookingExtra->quantity }}@endif — {{ Money::format($bookingExtra->total, $booking->currency, $locale) }}
@endforeach

**{{ __('booking.total', [], $locale) }}: {{ Money::format($booking->total, $booking->currency, $locale) }}**
@if ($booking->balance_due > 0)
{{ __('booking.balance', [], $locale) }}: {{ Money::format($booking->balance_due, $booking->currency, $locale) }}
@endif

<x-mail::button :url="$manageUrl">
{{ __('booking.manage_title', [], $locale) }}
</x-mail::button>

{{ __('booking.manage_link', [], $locale) }}

{{ __('common.check_in_from', ['time' => config('doba.checkin_from')], $locale) }} ·
{{ __('common.check_out_until', ['time' => config('doba.checkout_until')], $locale) }}
</x-mail::message>
