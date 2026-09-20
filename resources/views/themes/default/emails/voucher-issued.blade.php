@php $locale = $voucher->locale; @endphp
<x-mail::message>
# {{ __('vouchers.one', [], $locale) }} · {{ $amount }}

{{ __('vouchers.mail_issued_intro', ['name' => $voucher->buyer_name, 'amount' => $amount], $locale) }}

{{ __('vouchers.mail_issued_how', ['code' => $voucher->code, 'date' => $voucher->expires_on?->translatedFormat('j F Y') ?? '—'], $locale) }}

{{ $hotelName }}
</x-mail::message>
