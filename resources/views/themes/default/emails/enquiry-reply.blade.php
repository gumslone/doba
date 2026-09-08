<x-mail::message>
{{-- The hotelier's text, line breaks kept. Escaped first, then wrapped:
     the markdown mail would otherwise fold a carefully spaced answer
     into one paragraph, and must never render tags a guest typed. --}}
{!! nl2br(e($body)) !!}

{{ $hotelName }}

<x-mail::panel>
{{ $enquiry->message }}
</x-mail::panel>
</x-mail::message>
