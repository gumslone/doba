<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\BulkAvailabilityUpdate;
use App\Domain\Pricing\RateResolver;
use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The availability grid (§12) — the screen a hotelier actually lives in.
 *
 * Room types as rows, the month's dates as columns, each cell showing the
 * resolved price and what is sold. Everything is edited through the bulk
 * panel, which is the only thing that scales: a season change is one
 * operation over a date range and a weekday filter, not ninety clicks.
 */
class AdminAvailabilityController extends Controller
{
    public function index(Request $request, RateResolver $rates): View
    {
        $month = $this->month($request);
        $end = $month->endOfMonth();

        $roomTypes = RoomType::query()->ordered()->with('translations')->get();

        $rows = Availability::query()
            ->whereIn('room_type_id', $roomTypes->modelKeys())
            ->whereBetween('date', [$month->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('room_type_id')
            ->map(static fn ($group) => $group->keyBy(
                static fn (Availability $row): string => $row->date->toDateString()
            ));

        // Resolved price per cell, so the grid shows what a guest would
        // actually be quoted — override, else season rate, else default
        // (§7) — rather than only the manual overrides.
        $grid = [];

        foreach ($roomTypes as $roomType) {
            $cells = [];

            for ($date = $month; $date <= $end; $date = $date->addDay()) {
                $row = $rows->get($roomType->id)?->get($date->toDateString());

                $cells[] = [
                    'date' => $date,
                    'row' => $row,
                    'price' => $row ? $rates->nightlyPrice($roomType, $date, $row) : null,
                ];
            }

            $grid[] = ['room_type' => $roomType, 'cells' => $cells];
        }

        return view('admin.availability.index', [
            'month' => $month,
            'grid' => $grid,
            'roomTypes' => $roomTypes,
            'previous' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            // The horizon availability:extend has generated to: past it,
            // every cell is missing and therefore unsellable (§5).
            'horizon' => Availability::query()->max('date'),
        ]);
    }

    public function update(Request $request, BulkAvailabilityUpdate $bulk): RedirectResponse
    {
        $validated = $request->validate([
            'room_type_ids' => ['required', 'array', 'min:1'],
            'room_type_ids.*' => ['integer', 'exists:room_types,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'min:1', 'max:7'],

            // Entered in major units by a human; stored in minor units (§5).
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'allotment' => ['nullable', 'integer', 'min:0', 'max:999'],
            'min_stay' => ['nullable', 'integer', 'min:1', 'max:255'],
            'max_stay' => ['nullable', 'integer', 'min:1', 'max:255'],
            'closed' => ['nullable', 'in:0,1'],
            'closed_to_arrival' => ['nullable', 'in:0,1'],
            'closed_to_departure' => ['nullable', 'in:0,1'],
        ]);

        $weekdays = $validated['weekdays'] ?? [];

        $mask = $weekdays === []
            ? BulkAvailabilityUpdate::ALL_WEEK
            : array_reduce($weekdays, static fn (int $carry, int $day): int => $carry | (1 << ($day - 1)), 0);

        $result = $bulk->apply(
            array_map('intval', $validated['room_type_ids']),
            CarbonImmutable::parse($validated['from']),
            CarbonImmutable::parse($validated['to']),
            $mask,
            [
                'price' => isset($validated['price']) ? (int) round(((float) $validated['price']) * 100) : null,
                'allotment' => $validated['allotment'] ?? null,
                'min_stay' => $validated['min_stay'] ?? null,
                'max_stay' => $validated['max_stay'] ?? null,
                'closed' => isset($validated['closed']) ? (bool) $validated['closed'] : null,
                'closed_to_arrival' => isset($validated['closed_to_arrival']) ? (bool) $validated['closed_to_arrival'] : null,
                'closed_to_departure' => isset($validated['closed_to_departure']) ? (bool) $validated['closed_to_departure'] : null,
            ],
        );

        $redirect = redirect('/admin/availability?month='.CarbonImmutable::parse($validated['from'])->format('Y-m'));

        if ($result['refused'] !== []) {
            return $redirect->with('saved', __('admin.grid_partial', [
                'count' => $result['updated'],
                'dates' => implode(', ', array_slice($result['refused'], 0, 5)),
            ]));
        }

        return $redirect->with('saved', __('admin.grid_updated', ['count' => $result['updated']]));
    }

    protected function month(Request $request): CarbonImmutable
    {
        $month = (string) $request->query('month', '');

        return preg_match('/^\d{4}-\d{2}$/', $month)
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay()
            : CarbonImmutable::today(config('doba.timezone'))->startOfMonth();
    }
}
