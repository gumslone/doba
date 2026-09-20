@php
    use App\Support\Mail\Wording;
    use App\Support\Money;

    $locale = $booking->locale;
@endphp

<x-mail::message>
# {{ Wording::text('recovery_heading', $words, $locale) }}

{{ Wording::text('recovery_intro', $words, $locale) }}

@foreach ($booking->rooms->unique('room_type_id') as $room)
**{{ $room->roomType?->t('name', $locale) }}**
@endforeach
{{ $booking->check_in->translatedFormat('j M Y') }} – {{ $booking->check_out->translatedFormat('j M Y') }} · {{ __('booking.nights', ['count' => $booking->nights], $locale) }}
{{ __('booking.total', [], $locale) }}: {{ Money::format($booking->total, $booking->currency, $locale) }}

<x-mail::button :url="$resumeUrl">
{{ __('mail.recovery_button', [], $locale) }}
</x-mail::button>

{{ Wording::text('recovery_outro', $words, $locale) }}

{{ $words['hotel'] }}
</x-mail::message>
