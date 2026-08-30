@extends('admin.layout', ['title' => __('admin.guests')])

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('admin.guests') }}</h1>
            <p class="mt-1 text-sm text-neutral-600">{{ __('admin.guests_intro') }}</p>
        </div>

        {{-- The consenting list goes wherever the newsletter is written.
             The count is next to the button so nobody exports to find out. --}}
        <a href="/admin/guests/export-consenting"
           class="rounded border border-neutral-300 px-4 py-2 text-sm">
            {{ __('admin.guests_export_consenting', ['count' => $consenting]) }}
        </a>
    </div>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif

    <form method="GET" action="/admin/guests" class="mb-6">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('admin.guests_search') }}"
               class="w-full max-w-md rounded border border-neutral-300 px-3 py-2">
    </form>

    <div class="overflow-x-auto rounded border border-neutral-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-neutral-200 text-left text-neutral-500">
                <tr>
                    <th class="px-4 py-3">{{ __('admin.guest') }}</th>
                    <th class="px-4 py-3">{{ __('admin.guest_stays') }}</th>
                    <th class="px-4 py-3">{{ __('admin.guest_value') }}</th>
                    <th class="px-4 py-3">{{ __('admin.guest_country') }}</th>
                    <th class="px-4 py-3">{{ __('admin.guest_consent') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($guests as $guest)
                    <tr class="border-b border-neutral-100 last:border-0">
                        <td class="px-4 py-3">
                            <a href="/admin/guests/{{ $guest->id }}" class="font-medium underline-offset-2 hover:underline">
                                {{ $guest->last_name }}, {{ $guest->first_name }}
                            </a>
                            @if ($guest->isAnonymised())
                                <span class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">{{ __('admin.guest_anonymised_badge') }}</span>
                            @else
                                <div class="text-xs text-neutral-500">{{ $guest->email }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $guest->stays_count }}
                            @if ($guest->stays_count >= 2)
                                <span class="ml-1 rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800">{{ __('admin.guest_returning') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ \App\Support\Money::format($guest->total_spent) }}</td>
                        <td class="px-4 py-3">{{ $guest->country }}</td>
                        <td class="px-4 py-3">
                            @if ($guest->marketing_consent)
                                <span class="text-green-700">{{ __('admin.yes') }}</span>
                            @else
                                <span class="text-neutral-400">{{ __('admin.no') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-neutral-500">{{ __('admin.guests_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $guests->links() }}</div>
@endsection
