@extends('admin.layout', ['title' => __('admin.housekeeping')])

@section('content')
    {{-- Read on a phone in a corridor: one column, big type, one big
         button per door, and nothing that needs a precise tap. --}}
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">{{ __('admin.housekeeping') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">
            {{ trans_choice('admin.housekeeping_summary', $priority->count() + $dirty->count(), ['count' => $priority->count() + $dirty->count()]) }}
            @if ($priority->isNotEmpty())
                · {{ trans_choice('admin.housekeeping_arriving', $priority->count(), ['count' => $priority->count()]) }}
            @endif
            @if ($departing->isNotEmpty())
                · {{ trans_choice('admin.housekeeping_departing', $departing->count(), ['count' => $departing->count()]) }}
            @endif
        </p>
    </div>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif

    @if ($listed === 0)
        <p class="rounded border border-neutral-200 bg-white p-5 text-neutral-600">
            {{ __('admin.housekeeping_no_rooms') }} <a href="/admin/rooms" class="underline">{{ __('admin.rooms') }}</a>
        </p>
    @endif

    @foreach ([
        ['key' => 'priority', 'rows' => $priority, 'tone' => 'border-amber-300', 'action' => 'clean'],
        ['key' => 'dirty', 'rows' => $dirty, 'tone' => 'border-neutral-200', 'action' => 'clean'],
        ['key' => 'departing', 'rows' => $departing, 'tone' => 'border-neutral-200', 'action' => null],
        ['key' => 'out_of_order', 'rows' => $outOfOrder, 'tone' => 'border-red-200', 'action' => null],
    ] as $group)
        @if ($group['rows']->isNotEmpty())
            <section class="mb-6">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider text-neutral-500">
                    {{ __('admin.housekeeping_group_'.$group['key']) }} <span class="text-neutral-400">{{ $group['rows']->count() }}</span>
                </h2>
                <ul class="space-y-2">
                    @foreach ($group['rows'] as $door)
                        <li class="flex items-center justify-between gap-3 rounded-lg border {{ $group['tone'] }} bg-white px-4 py-3">
                            <div class="min-w-0">
                                <div class="text-2xl font-semibold leading-tight">{{ $door->number }}</div>
                                <div class="truncate text-sm text-neutral-500">
                                    {{ $door->roomType?->t('name') ?? $door->roomType?->code }}
                                    @if ($door->floor) · {{ $door->floor }} @endif
                                    @if ($arrivals->has($door->id))
                                        · <span class="font-medium text-amber-800">
                                            {{ $arrivals->get($door->id) ? __('admin.arriving_at', ['time' => $arrivals->get($door->id)]) : __('admin.housekeeping_arriving_today') }}
                                        </span>
                                    @endif
                                    @if ($departures->has($door->id) && $door->status !== 'dirty')
                                        · {{ __('admin.until_time', ['time' => $departures->get($door->id)]) }}
                                    @endif
                                </div>
                                @if ($door->notes)
                                    <div class="mt-1 text-sm text-neutral-600">{{ $door->notes }}</div>
                                @endif
                            </div>
                            @if ($group['action'] === 'clean')
                                <form method="POST" action="/admin/housekeeping/{{ $door->id }}/clean" class="shrink-0">
                                    @csrf
                                    <button type="submit" class="rounded-lg bg-green-700 px-5 py-3 text-base font-medium text-white">
                                        {{ __('admin.housekeeping_mark_clean') }}
                                    </button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach

    @if ($clean->isNotEmpty())
        <details class="mb-6">
            <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wider text-neutral-500">
                {{ __('admin.housekeeping_group_clean') }} <span class="text-neutral-400">{{ $clean->count() }}</span>
            </summary>
            <ul class="mt-2 space-y-2">
                @foreach ($clean as $door)
                    <li class="flex items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-white px-4 py-3">
                        <div>
                            <span class="text-xl font-semibold">{{ $door->number }}</span>
                            <span class="ml-2 text-sm text-neutral-500">{{ $door->roomType?->t('name') ?? $door->roomType?->code }}@if ($door->floor) · {{ $door->floor }}@endif</span>
                        </div>
                        <form method="POST" action="/admin/housekeeping/{{ $door->id }}/dirty" class="shrink-0">
                            @csrf
                            <button type="submit" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm">{{ __('admin.housekeeping_mark_dirty') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
