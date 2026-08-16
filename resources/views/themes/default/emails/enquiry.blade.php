<x-mail::message>
# {{ __('contact.mail_subject', ['name' => $enquiry->name]) }}

**{{ __('contact.name') }}:** {{ $enquiry->name }}
**{{ __('contact.email') }}:** {{ $enquiry->email }}
@if ($enquiry->phone)
**{{ __('contact.phone') }}:** {{ $enquiry->phone }}
@endif
@if ($enquiry->check_in)
**{{ __('contact.check_in') }}:** {{ $enquiry->check_in->toDateString() }}@if ($enquiry->check_out) → {{ $enquiry->check_out->toDateString() }}@endif
@endif

{{ $enquiry->message }}

<x-mail::panel>
{{ __('contact.mail_footer', ['locale' => strtoupper($enquiry->locale)]) }}
</x-mail::panel>
</x-mail::message>
