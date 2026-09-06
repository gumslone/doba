<x-mail::message>
# {{ $subjectLine }}

@foreach ($lines as $line)
- {{ $line }}
@endforeach

This message is sent at most once an hour per problem. The health check under **Admin → Update** shows the current state.

<x-mail::button :url="$adminUrl">
Open the admin
</x-mail::button>
</x-mail::message>
