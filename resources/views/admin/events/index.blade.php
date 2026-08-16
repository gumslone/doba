@extends('admin.layout', ['title' => __('admin.events')])

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('admin.events') }}</h1>
        <a href="/admin/events/create" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.new_event') }}</a>
    </div>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($events as $event)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <a href="/admin/events/{{ $event->id }}/edit" class="font-medium hover:underline">
                        {{ $event->t('title') ?? '#'.$event->id }}
                    </a>
                    <p class="text-sm text-neutral-500">
                        {{ $event->starts_at->format('Y-m-d H:i') }} ·
                        {{ implode(', ', $event->translatedLocales()) ?: '—' }}
                        @unless ($event->is_published) · <span class="text-amber-600">{{ __('admin.draft') }}</span> @endunless
                        @if ($event->starts_at->isPast() && ! $event->ends_at?->isFuture()) · <span class="text-neutral-400">{{ __('admin.past') }}</span> @endif
                    </p>
                </div>
                <form method="POST" action="/admin/events/{{ $event->id }}"
                      onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                </form>
            </li>
        @empty
            <li class="px-4 py-6 text-neutral-500">—</li>
        @endforelse
    </ul>
@endsection
