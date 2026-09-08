@extends('admin.layout', ['title' => __('admin.enquiries')])

@section('content')
    @php
        $tabs = [
            'inbox' => __('admin.enquiries_tab_inbox'),
            'new' => __('admin.enquiries_tab_new'),
            'replied' => __('admin.enquiries_tab_replied'),
            'spam' => __('admin.enquiries_tab_spam'),
            'all' => __('admin.enquiries_tab_all'),
        ];
        $tabCount = fn (string $tab): ?int => match ($tab) {
            'new' => $counts['new'] ?? 0,
            'replied' => $counts['replied'] ?? 0,
            'spam' => $counts['spam'] ?? 0,
            'all' => array_sum($counts),
            default => array_sum($counts) - ($counts['spam'] ?? 0),
        };
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-semibold">{{ __('admin.enquiries') }}</h1>
        <p class="mt-1 max-w-2xl text-sm text-neutral-600">{{ __('admin.enquiries_intro') }}</p>
    </div>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif

    <nav class="mb-4 flex flex-wrap gap-2 text-sm" aria-label="{{ __('admin.enquiries') }}">
        @foreach ($tabs as $key => $label)
            <a href="/admin/enquiries{{ $key === 'inbox' ? '' : '?status='.$key }}"
               @class([
                   'rounded-full border px-3 py-1',
                   'border-neutral-900 bg-neutral-900 text-white' => $filter === $key,
                   'border-neutral-300 text-neutral-700 hover:bg-neutral-50' => $filter !== $key,
               ])>
                {{ $label }} <span class="opacity-70">{{ $tabCount($key) }}</span>
            </a>
        @endforeach
    </nav>

    <div class="overflow-x-auto rounded border border-neutral-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-neutral-200 text-left text-neutral-500">
                <tr>
                    <th class="px-4 py-3">{{ __('admin.enquiry_from') }}</th>
                    <th class="px-4 py-3">{{ __('admin.enquiry_message') }}</th>
                    <th class="px-4 py-3">{{ __('admin.enquiry_dates') }}</th>
                    <th class="px-4 py-3">{{ __('admin.enquiry_received') }}</th>
                    <th class="px-4 py-3">{{ __('admin.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($enquiries as $enquiry)
                    <tr @class(['border-b border-neutral-100 last:border-0', 'font-medium' => $enquiry->status === \App\Enums\EnquiryStatus::New])>
                        <td class="px-4 py-3">
                            <a href="/admin/enquiries/{{ $enquiry->id }}" class="underline-offset-2 hover:underline">{{ $enquiry->name }}</a>
                            <div class="text-xs font-normal text-neutral-500">{{ $enquiry->email }} · {{ strtoupper($enquiry->locale) }}</div>
                        </td>
                        <td class="max-w-md px-4 py-3 font-normal text-neutral-700">{{ \Illuminate\Support\Str::limit($enquiry->message, 120) }}</td>
                        <td class="px-4 py-3 font-normal text-neutral-500">
                            @if ($enquiry->check_in)
                                {{ $enquiry->check_in->toDateString() }}@if ($enquiry->check_out) → {{ $enquiry->check_out->toDateString() }}@endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 font-normal text-neutral-500">{{ $enquiry->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3 font-normal">
                            <span @class([
                                'rounded px-1.5 py-0.5 text-xs',
                                'bg-amber-100 text-amber-800' => $enquiry->status === \App\Enums\EnquiryStatus::New,
                                'bg-neutral-100 text-neutral-600' => $enquiry->status === \App\Enums\EnquiryStatus::Read,
                                'bg-green-100 text-green-800' => $enquiry->status === \App\Enums\EnquiryStatus::Replied,
                                'bg-red-50 text-red-700' => $enquiry->status === \App\Enums\EnquiryStatus::Spam,
                            ])>{{ __('admin.enquiry_status_'.$enquiry->status->value) }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-neutral-500">{{ __('admin.enquiries_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $enquiries->links() }}</div>
@endsection
