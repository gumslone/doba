@extends('admin.layout', ['title' => __('admin.reports')])

@section('content')
    @php
        use App\Support\Money;
        $pct = fn (float $v): string => number_format($v * 100, 1).'%';
        $delta = fn (?float $v): string => $v === null ? '—' : ($v >= 0 ? '+' : '').number_format($v * 100, 1).'%';
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <h1 class="text-2xl font-semibold">{{ __('admin.reports') }}</h1>

        <form method="GET" class="flex flex-wrap items-end gap-2 text-sm">
            <div>
                <label for="from" class="block text-xs text-neutral-500">{{ __('admin.from') }}</label>
                <input type="date" id="from" name="from" value="{{ $from->toDateString() }}"
                       class="rounded border border-neutral-300 px-3 py-1.5">
            </div>
            <div>
                <label for="to" class="block text-xs text-neutral-500">{{ __('admin.to') }}</label>
                <input type="date" id="to" name="to" value="{{ $to->toDateString() }}"
                       class="rounded border border-neutral-300 px-3 py-1.5">
            </div>
            <button type="submit" class="rounded border border-neutral-300 px-3 py-1.5 hover:bg-neutral-50">
                {{ __('admin.show') }}
            </button>
            <a href="/admin/reports/export?from={{ $from->toDateString() }}&to={{ $to->toDateString() }}"
               class="rounded border border-neutral-300 px-3 py-1.5 hover:bg-neutral-50">{{ __('admin.export_csv') }}</a>
        </form>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['admin.occupancy', $pct($summary['occupancy']), __('admin.occupancy_hint', ['sold' => $summary['occupied_nights'], 'capacity' => $summary['capacity']])],
            ['admin.adr', Money::format($summary['adr']), __('admin.adr_hint')],
            ['admin.revpar', Money::format($summary['revpar']), __('admin.revpar_hint')],
            ['admin.room_revenue', Money::format($summary['room_revenue']), __('admin.room_revenue_hint')],
        ] as [$label, $value, $hint])
            <div class="rounded border border-neutral-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __($label) }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ $value }}</p>
                <p class="mt-1 text-xs text-neutral-500">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    @if ($summary['ota_nights'] > 0)
        {{-- Said plainly rather than folded into the averages: an iCal sync
             knows the room is gone and nothing about the money. --}}
        <p class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {{ __('admin.ota_caveat', ['nights' => $summary['ota_nights']]) }}
        </p>
    @endif

    <section class="mt-8 rounded border border-neutral-200 bg-white p-5">
        <h2 class="font-medium">{{ __('admin.pace') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ __('admin.pace_hint') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('admin.on_the_books') }}</p>
                <p class="mt-1 text-lg font-semibold">
                    {{ $pace['now']['nights'] }} {{ __('admin.nights') }} ·
                    {{ Money::format($pace['now']['revenue']) }}
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('admin.same_point_last_year') }}</p>
                <p class="mt-1 text-lg">
                    {{ $pace['last_year']['nights'] }} {{ __('admin.nights') }} ·
                    {{ Money::format($pace['last_year']['revenue']) }}
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('admin.change') }}</p>
                <p class="mt-1 text-lg font-semibold">
                    {{ $delta($pace['nights_change']) }} {{ __('admin.nights') }} ·
                    {{ $delta($pace['revenue_change']) }} {{ __('admin.revenue') }}
                </p>
            </div>
        </div>
    </section>

    <section class="mt-8 rounded border border-neutral-200 bg-white p-5">
        <h2 class="font-medium">{{ __('admin.channel_mix') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ __('admin.channel_mix_hint') }}</p>

        <ul class="mt-4 space-y-2 text-sm">
            @forelse ($mix as $entry)
                <li>
                    <div class="flex items-center justify-between gap-3">
                        <span>{{ __('admin.source_'.$entry['source']) }}</span>
                        <span class="text-neutral-500">
                            {{ $entry['nights'] }} {{ __('admin.nights') }} ·
                            {{ $entry['revenue'] > 0 ? Money::format($entry['revenue']) : __('admin.no_rate_data') }}
                            · {{ $pct($entry['share']) }}
                        </span>
                    </div>
                    <div class="mt-1 h-1.5 rounded bg-neutral-100">
                        <div class="h-1.5 rounded bg-neutral-900" style="width: {{ round($entry['share'] * 100, 1) }}%"></div>
                    </div>
                </li>
            @empty
                <li class="text-neutral-500">{{ __('admin.no_bookings_yet') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="mt-8 overflow-x-auto rounded border border-neutral-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <th class="px-4 py-3">{{ __('admin.month') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.occupancy') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.adr') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.revpar') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.nights') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.revenue') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @foreach ($months as $month)
                    <tr>
                        <td class="px-4 py-2">{{ $month['month']->translatedFormat('F Y') }}</td>
                        <td class="px-4 py-2 text-right">{{ $pct($month['occupancy']) }}</td>
                        <td class="px-4 py-2 text-right">{{ Money::format($month['adr']) }}</td>
                        <td class="px-4 py-2 text-right">{{ Money::format($month['revpar']) }}</td>
                        <td class="px-4 py-2 text-right">{{ $month['occupied_nights'] }}</td>
                        <td class="px-4 py-2 text-right">{{ Money::format($month['room_revenue']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection
