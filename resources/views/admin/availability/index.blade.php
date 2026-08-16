@extends('admin.layout', ['title' => __('admin.availability')])

@section('content')
    @php use App\Support\Money; @endphp

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">{{ __('admin.availability') }}</h1>

        <div class="flex items-center gap-2">
            <a href="/admin/availability?month={{ $previous }}"
               class="rounded border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-50">←</a>
            <span class="min-w-40 text-center font-medium">{{ $month->translatedFormat('F Y') }}</span>
            <a href="/admin/availability?month={{ $next }}"
               class="rounded border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-50">→</a>
        </div>
    </div>

    @if ($roomTypes->isEmpty())
        <p class="text-neutral-500">{{ __('common.no_rooms_yet') }}</p>
    @else
        {{-- Drag across cells to fill the panel below. Plain DOM: the CSP
             forbids eval, so no expression framework (see app.js). --}}
        <div class="overflow-x-auto rounded border border-neutral-200 bg-white" data-grid>
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-neutral-50 px-3 py-2 text-left font-medium">
                            {{ __('admin.room_types') }}
                        </th>
                        @foreach ($grid[0]['cells'] as $cell)
                            <th class="min-w-14 border-l border-neutral-100 px-1 py-2 text-center font-medium
                                       {{ $cell['date']->isWeekend() ? 'bg-neutral-100' : 'bg-neutral-50' }}">
                                <span class="block text-[10px] uppercase text-neutral-400">
                                    {{ $cell['date']->translatedFormat('D') }}
                                </span>
                                {{ $cell['date']->day }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($grid as $line)
                        <tr class="border-t border-neutral-200">
                            <th scope="row" class="sticky left-0 z-10 bg-white px-3 py-2 text-left font-medium">
                                {{ $line['room_type']->t('name') ?? $line['room_type']->code }}
                            </th>

                            @foreach ($line['cells'] as $cell)
                                @php
                                    $row = $cell['row'];
                                    $left = $row ? max(0, $row->allotment - $row->booked - $row->held) : null;
                                @endphp
                                <td class="border-l border-neutral-100 p-0 text-center align-top">
                                    <button type="button"
                                            data-cell
                                            data-date="{{ $cell['date']->toDateString() }}"
                                            data-room-type="{{ $line['room_type']->id }}"
                                            @disabled(! $row)
                                            class="h-14 w-full px-1 py-1 text-[11px] leading-tight
                                                   @if (! $row) cursor-not-allowed bg-neutral-100 text-neutral-400
                                                   @elseif ($row->closed) bg-red-50 text-red-700
                                                   @elseif ($left === 0) bg-amber-50 text-amber-800
                                                   @else hover:bg-neutral-50 @endif">
                                        @if (! $row)
                                            —
                                        @else
                                            <span class="block font-medium">{{ $cell['price'] ? Money::format($cell['price']) : '–' }}</span>
                                            <span class="block text-neutral-500">{{ $left }}/{{ $row->allotment }}</span>
                                            <span class="block text-[9px] tracking-wide text-neutral-400">
                                                @if ($row->min_stay > 1) {{ $row->min_stay }}N @endif
                                                @if ($row->closed_to_arrival) ·CTA @endif
                                                @if ($row->closed_to_departure) ·CTD @endif
                                            </span>
                                        @endif
                                    </button>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-neutral-500">
            {{ __('admin.grid_legend') }}
            @if ($horizon)
                · {{ __('admin.grid_horizon', ['date' => $horizon]) }}
            @endif
        </p>

        <form method="POST" action="/admin/availability" class="mt-8 space-y-5 rounded border border-neutral-200 bg-white p-5">
            @csrf
            @method('PUT')

            <div>
                <h2 class="font-semibold">{{ __('admin.bulk_edit') }}</h2>
                <p class="mt-1 text-sm text-neutral-500">{{ __('admin.bulk_hint') }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <div>
                    <label for="from" class="block text-sm font-medium">{{ __('booking.check_in') }}</label>
                    <input type="date" id="from" name="from" required data-grid-from
                           value="{{ $month->toDateString() }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="to" class="block text-sm font-medium">{{ __('booking.check_out') }}</label>
                    <input type="date" id="to" name="to" required data-grid-to
                           value="{{ $month->endOfMonth()->toDateString() }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div class="sm:col-span-2">
                    <span class="block text-sm font-medium">{{ __('admin.weekdays') }}</span>
                    <div class="mt-2 flex flex-wrap gap-3 text-sm">
                        @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $iso => $label)
                            <label class="flex items-center gap-1.5">
                                <input type="checkbox" name="weekdays[]" value="{{ $iso }}">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-neutral-500">{{ __('admin.weekdays_hint') }}</p>
                </div>
            </div>

            <fieldset>
                <legend class="text-sm font-medium">{{ __('admin.room_types') }}</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    @foreach ($roomTypes as $roomType)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="room_type_ids[]" value="{{ $roomType->id }}"
                                   data-grid-room="{{ $roomType->id }}" checked>
                            {{ $roomType->t('name') ?? $roomType->code }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid gap-4 border-t border-neutral-100 pt-5 sm:grid-cols-4">
                <div>
                    <label for="price" class="block text-sm font-medium">{{ __('admin.price') }} ({{ config('doba.currency') }})</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" placeholder="—"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="allotment" class="block text-sm font-medium">{{ __('admin.allotment') }}</label>
                    <input type="number" id="allotment" name="allotment" min="0" placeholder="—"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="min_stay" class="block text-sm font-medium">{{ __('admin.min_stay') }}</label>
                    <input type="number" id="min_stay" name="min_stay" min="1" placeholder="—"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="max_stay" class="block text-sm font-medium">{{ __('admin.max_stay') }}</label>
                    <input type="number" id="max_stay" name="max_stay" min="1" placeholder="—"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ([
                    'closed' => __('admin.stop_sell'),
                    'closed_to_arrival' => __('admin.cta'),
                    'closed_to_departure' => __('admin.ctd'),
                ] as $field => $label)
                    <div>
                        <label for="{{ $field }}" class="block text-sm font-medium">{{ $label }}</label>
                        <select id="{{ $field }}" name="{{ $field }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                            <option value="">{{ __('admin.leave_unchanged') }}</option>
                            <option value="1">{{ __('admin.yes') }}</option>
                            <option value="0">{{ __('admin.no') }}</option>
                        </select>
                    </div>
                @endforeach
            </div>

            <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">{{ __('admin.apply') }}</button>
        </form>
    @endif
@endsection
