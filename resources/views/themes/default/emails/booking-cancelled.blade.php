@php
    use App\Support\Money;

    $locale = $booking->locale;
    $hotel = app(\App\Support\Hotel\HotelSettings::class);
    $money = fn (int $minor): string => (string) Money::format($minor, $booking->currency, $locale);
    // What is still to come back by the way it was paid, after anything
    // that has already gone back onto a gift voucher.
    $owed = $booking->refund_due;
@endphp

<x-mail::message>
# {{ __('mail.cancelled_heading', [], $locale) }}

{{ __('mail.cancelled_intro', ['hotel' => $hotel->name], $locale) }}

**{{ __('booking.reference', [], $locale) }}:** {{ $booking->reference }}
**{{ __('booking.dates', [], $locale) }}:** {{ $booking->check_in->translatedFormat('j M Y') }} – {{ $booking->check_out->translatedFormat('j M Y') }}

@if ($restored > 0)
{{ __('mail.cancelled_voucher', ['amount' => $money($restored)], $locale) }}

@endif
@if ($owed > 0)
{{ __('mail.cancelled_refund', ['amount' => $money($owed)], $locale) }}
@elseif ($booking->paid_amount > 0)
{{ __('mail.cancelled_no_refund', ['amount' => $money($booking->paid_amount)], $locale) }}
@elseif ($restored === 0)
{{ __('mail.cancelled_nothing_paid', [], $locale) }}
@endif

{{ __('mail.cancelled_outro', ['hotel' => $hotel->name], $locale) }}
</x-mail::message>
