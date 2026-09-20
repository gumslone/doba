@php $locale = $voucher->locale; @endphp
<x-mail::message>
# {{ __('vouchers.ordered_title', [], $locale) }}

{{ __('vouchers.mail_ordered_intro', ['name' => $voucher->buyer_name, 'code' => $voucher->code, 'amount' => $amount], $locale) }}

@if (trim($instructions) !== '')
<x-mail::panel>
**{{ __('vouchers.mail_ordered_instructions', [], $locale) }}: {{ $amount }} · {{ $voucher->code }}**

{!! nl2br(e($instructions)) !!}
</x-mail::panel>
@endif

{{ __('vouchers.mail_ordered_outro', [], $locale) }}

{{ $hotelName }}
</x-mail::message>
