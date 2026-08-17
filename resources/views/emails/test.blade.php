<x-mail::message>
# {{ __('mail.test_heading') }}

{{ __('mail.test_body', ['hotel' => $hotelName]) }}

<x-mail::panel>
{{ $code }}
</x-mail::panel>

{{ __('mail.test_instruction') }}

{{ __('mail.test_signoff') }}
</x-mail::message>
