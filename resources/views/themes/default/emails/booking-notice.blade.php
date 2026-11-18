@php
    use App\Support\Money;

    $money = fn (int $minor): string => (string) Money::format($minor, $booking->currency, $locale);
    $guest = $booking->guest;
@endphp

<x-mail::message>
# {{ __('mail.notice_'.$event.'_heading', [], $locale) }}

**{{ $booking->reference }}** · {{ trim($guest?->first_name.' '.$guest?->last_name) }}
{{ $booking->check_in->translatedFormat('D j M Y') }} – {{ $booking->check_out->translatedFormat('D j M Y') }} · {{ trans_choice('mail.notice_nights', $booking->nights, ['count' => $booking->nights], $locale) }} · {{ trans_choice('mail.notice_guests', $booking->adults + $booking->children, ['count' => $booking->adults + $booking->children], $locale) }}

@foreach ($booking->rooms as $room)
- {{ $room->roomType?->t('name', $locale) }} — {{ $money($room->price_total) }}
@endforeach
@foreach ($booking->extras as $bookingExtra)
- {{ $bookingExtra->extra?->t('name', $locale) }}@if ($bookingExtra->quantity > 1) × {{ $bookingExtra->quantity }}@endif — {{ $money($bookingExtra->total) }}
@endforeach

**{{ __('booking.total', [], $locale) }}: {{ $money($booking->total) }}** · {{ __('mail.notice_paid', ['amount' => $money($booking->paid_amount)], $locale) }}

@if ($event === 'cancelled')
@if ($restored > 0)
{{ __('mail.notice_voucher_restored', ['amount' => $money($restored)], $locale) }}

@endif
@if ($booking->refund_due > 0)
**{{ __('mail.notice_refund_owed', ['amount' => $money($booking->refund_due)], $locale) }}**
@elseif ($booking->paid_amount > 0)
{{ __('mail.notice_no_refund', ['amount' => $money($booking->paid_amount)], $locale) }}
@endif
@elseif ($booking->guest_notes)
{{ __('mail.notice_requests', [], $locale) }} {{ $booking->guest_notes }}
@endif

<x-mail::button :url="$url">
{{ __('mail.notice_open', [], $locale) }}
</x-mail::button>
</x-mail::message>
