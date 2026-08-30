<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The doors themselves (§5, phase 2).
 *
 * Numbers, floors and housekeeping state. Deliberately NOT where
 * capacity lives: the website sells `total_units` off the availability
 * grid whether or not any doors are listed here, and the page says so
 * when the two disagree rather than silently trusting either.
 */
class AdminRoomController extends Controller
{
    public function index(): View
    {
        return view('admin.rooms.index', [
            'roomTypes' => RoomType::query()
                ->ordered()
                ->with(['translations', 'rooms' => fn ($q) => $q->orderBy('number')])
                ->get(),
            'statuses' => Room::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_type_id' => ['required', 'exists:room_types,id'],
            'number' => ['required', 'string', 'max:32', 'unique:rooms,number'],
            'floor' => ['nullable', 'string', 'max:32'],
        ]);

        Room::create($validated);

        return redirect('/admin/rooms')->with('saved', __('admin.room_created', ['number' => $validated['number']]));
    }

    public function update(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Room::STATUSES)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $room->update($validated);

        return redirect('/admin/rooms')->with('saved', __('admin.room_saved', ['number' => $room->number]));
    }

    public function destroy(Room $room): RedirectResponse
    {
        // History survives: booking_rooms.room_id nulls out rather than
        // taking old stays' records with the door.
        $room->delete();

        return redirect('/admin/rooms')->with('saved', __('admin.room_deleted', ['number' => $room->number]));
    }
}
