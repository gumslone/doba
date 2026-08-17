@extends('admin.layout', ['title' => __('admin.channels')])

@section('content')
    <div class="mb-2 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('admin.channels') }}</h1>
        <a href="/admin/channels/create" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.new_feed') }}</a>
    </div>

    {{-- Said plainly, because a hotelier who believes iCal prevents
         overbooking will eventually be surprised at the front desk. --}}
    <p class="mb-6 max-w-3xl text-sm text-neutral-500">{{ __('admin.channel_limits') }}</p>

    @if ($review->isNotEmpty())
        <section class="mb-8 rounded border border-amber-300 bg-amber-50 p-4">
            <h2 class="font-medium text-amber-900">{{ __('admin.channel_review_title') }}</h2>
            <p class="mt-1 text-sm text-amber-800">{{ __('admin.channel_review_help') }}</p>

            <ul class="mt-4 space-y-3">
                @foreach ($review as $block)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded border border-amber-200 bg-white px-3 py-2">
                        <div class="text-sm">
                            <strong>{{ $block->roomType?->t('name') ?? '—' }}</strong>
                            {{ $block->check_in->translatedFormat('j M') }} –
                            {{ $block->check_out->translatedFormat('j M Y') }}
                            <span class="text-neutral-500">· {{ $block->feed?->name }} · {{ $block->external_uid }}</span>
                        </div>
                        <form method="POST" action="/admin/channels/review/{{ $block->id }}" class="flex gap-2">
                            @csrf
                            <button name="decision" value="keep"
                                    class="rounded border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-50">
                                {{ __('admin.channel_keep') }}
                            </button>
                            <button name="decision" value="release"
                                    onsubmit="return confirm('{{ __('admin.confirm_delete') }}')"
                                    class="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50">
                                {{ __('admin.channel_release') }}
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($feeds as $feed)
            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div>
                    <a href="/admin/channels/{{ $feed->id }}/edit" class="font-medium hover:underline">{{ $feed->name }}</a>
                    <p class="text-sm text-neutral-500">
                        {{ __('admin.channel_'.$feed->channel) }} ·
                        {{ $feed->roomType?->t('name') ?? '—' }}
                        @unless ($feed->is_active) · <span class="text-amber-600">{{ __('admin.draft') }}</span> @endunless
                    </p>
                    <p class="mt-1 text-xs {{ $feed->isUnhealthy() ? 'text-red-600' : 'text-neutral-500' }}">
                        @if ($feed->import_url === null)
                            {{ __('admin.channel_export_only') }}
                        @elseif ($feed->last_success_at === null)
                            {{ __('admin.channel_never_synced') }}
                        @else
                            {{ __('admin.channel_last_sync', ['when' => $feed->last_success_at->diffForHumans()]) }}
                        @endif
                        @if ($feed->last_error) · {{ $feed->last_error }} @endif
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    @if ($feed->import_url)
                        <form method="POST" action="/admin/channels/{{ $feed->id }}/sync">
                            @csrf
                            <button class="rounded border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-50">
                                {{ __('admin.channel_sync_now') }}
                            </button>
                        </form>
                    @endif
                    <form method="POST" action="/admin/channels/{{ $feed->id }}"
                          onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                    </form>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-neutral-500">{{ __('admin.no_feeds') }}</li>
        @endforelse
    </ul>

    <h2 class="mt-10 mb-2 text-lg font-medium">{{ __('admin.channel_export_title') }}</h2>
    <p class="mb-4 max-w-3xl text-sm text-neutral-500">{{ __('admin.channel_export_help') }}</p>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @foreach ($roomTypes as $roomType)
            <li class="px-4 py-3">
                <p class="font-medium">{{ $roomType->t('name') ?? $roomType->code }}</p>
                <input type="text" readonly value="{{ $roomType->icalUrl() }}" onclick="this.select()"
                       class="mt-1 w-full rounded border border-neutral-200 bg-neutral-50 px-2 py-1 font-mono text-xs">
            </li>
        @endforeach
    </ul>
@endsection
