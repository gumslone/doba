<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\BookingRoom;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Housekeeping's list (§12): the doors to clean, on a phone, in order.
 *
 * The rooms page is the fleet — numbers, floors, adding and removing.
 * This is the morning: which doors are dirty, which of those have a
 * guest arriving into them today (and at what time), which are still
 * occupied but leaving, and one big button per door. No guest names —
 * housekeeping needs the door, not the person.
 */
class AdminHousekeepingController extends Controller
{
    public function index(): View
    {
        $today = CarbonImmutable::today(config('doba.timezone'))->toDateString();

        $rooms = Room::query()
            ->with('roomType.translations')
            ->orderBy('floor')
            ->orderBy('number')
            ->get();

        // Doors with a confirmed guest arriving today, and when they said
        // they would come. A door that is dirty at 09:00 with a 14:00
        // arrival is the first one on the list.
        $arrivals = BookingRoom::query()
            ->whereNotNull('room_id')
            ->whereHas('booking', fn ($q) => $q
                ->where('check_in', $today)
                ->where('status', BookingStatus::Confirmed))
            ->with('booking:id,arrival_time')
            ->get()
            ->keyBy('room_id')
            ->map(static fn (BookingRoom $br): ?string => $br->booking?->arrival_time);

        // Doors still occupied by somebody due to leave today: not yet
        // cleanable, but the next thing that will be.
        $departures = BookingRoom::query()
            ->whereNotNull('room_id')
            ->whereHas('booking', fn ($q) => $q
                ->where('check_out', $today)
                ->where('status', BookingStatus::CheckedIn))
            ->with('booking')
            ->get()
            ->keyBy('room_id')
            ->map(static fn (BookingRoom $br): ?string => $br->booking?->departureTime());

        $dirty = $rooms->where('status', 'dirty');

        return view('admin.housekeeping.index', [
            'priority' => $dirty
                ->filter(fn (Room $room): bool => $arrivals->has($room->id))
                ->sortBy(fn (Room $room): string => $arrivals->get($room->id) ?? '99:99')
                ->values(),
            'dirty' => $dirty
                ->reject(fn (Room $room): bool => $arrivals->has($room->id))
                ->values(),
            'departing' => $rooms
                ->filter(fn (Room $room): bool => $room->status !== 'dirty' && $departures->has($room->id))
                ->sortBy(fn (Room $room): string => $departures->get($room->id) ?? '99:99')
                ->values(),
            'outOfOrder' => $rooms->where('status', 'out_of_order')->values(),
            'clean' => $rooms
                ->filter(fn (Room $room): bool => $room->status === 'clean' && ! $departures->has($room->id))
                ->values(),
            'arrivals' => $arrivals,
            'departures' => $departures,
            'listed' => $rooms->count(),
        ]);
    }

    public function clean(Room $room): RedirectResponse
    {
        // Only dirty turns clean here. An out-of-order door has a bigger
        // problem than the sheets, and is put back on the rooms page.
        if ($room->status === 'dirty') {
            $room->update(['status' => 'clean']);
        }

        return redirect('/admin/housekeeping')->with('saved', __('admin.housekeeping_cleaned', ['number' => $room->number]));
    }

    public function dirty(Room $room): RedirectResponse
    {
        if ($room->status === 'clean') {
            $room->update(['status' => 'dirty']);
        }

        return redirect('/admin/housekeeping')->with('saved', __('admin.housekeeping_dirtied', ['number' => $room->number]));
    }

    public static function dirtyCount(): int
    {
        return Room::query()->where('status', 'dirty')->count();
    }
}
