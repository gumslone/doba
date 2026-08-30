<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Booking\BookingService;
use App\Domain\FrontDesk\RoomAssignment;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The front desk: who is arriving, who is in the house, who has gone.
 *
 * The one screen a hotel actually stands in front of all morning, so it
 * answers the three questions asked at a desk rather than presenting a
 * booking list and leaving the reader to work them out.
 */
class AdminFrontDeskController extends Controller
{
    public function index(Request $request): View
    {
        $date = $this->date($request);

        $with = ['guest', 'rooms.roomType.translations', 'rooms.room'];

        return view('admin.front-desk.index', [
            'date' => $date,
            // Confirmed but not yet arrived. Ordered by the time the guest
            // said they would come, so the desk reads down the list as the
            // day happens; anyone who gave no time sorts last rather than
            // first, because an unknown arrival is not an early one.
            'arrivals' => Booking::query()
                ->with($with)
                ->where('check_in', $date->toDateString())
                ->where('status', BookingStatus::Confirmed)
                ->get()
                ->sortBy(fn (Booking $b): string => $b->arrival_time ?? '99:99')
                ->values(),

            // In the house tonight: arrived, not yet departed. Includes
            // stays that began before today — an occupied room is occupied
            // whether or not the guest arrived this morning.
            'inHouse' => Booking::query()
                ->with($with)
                ->where('status', BookingStatus::CheckedIn)
                ->where('check_in', '<=', $date->toDateString())
                ->orderBy('check_out')
                ->get(),

            // Due to leave today, still in the room.
            'departures' => Booking::query()
                ->with($with)
                ->where('check_out', $date->toDateString())
                ->where('status', BookingStatus::CheckedIn)
                ->get()
                ->sortBy(fn (Booking $b): string => $b->departureTime())
                ->values(),

            // Already gone — the room is free to clean and resell.
            'departed' => Booking::query()
                ->with($with)
                ->where('status', BookingStatus::CheckedOut)
                ->whereBetween('checked_out_at', [$date->startOfDay(), $date->endOfDay()])
                ->orderByDesc('checked_out_at')
                ->get(),

            'houseCheckout' => (string) config('doba.checkout_until', '11:00'),
            'houseCheckin' => (string) config('doba.checkin_from', '15:00'),

            // Doors exist only once the hotel has listed them; until
            // then the assignment UI stays entirely out of the way.
            'hasRooms' => Room::query()->exists(),
        ]);
    }

    /**
     * Pin a stay to a door, or move it to another one.
     */
    public function assignRoom(Request $request, Booking $booking, RoomAssignment $assignment): RedirectResponse
    {
        $validated = $request->validate([
            'booking_room_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
        ]);

        /** @var BookingRoom $bookingRoom */
        $bookingRoom = $booking->rooms()->findOrFail($validated['booking_room_id']);

        try {
            $assignment->assign(
                $bookingRoom,
                isset($validated['room_id']) ? Room::query()->findOrFail($validated['room_id']) : null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('desk_error', $e->getMessage());
        }

        return back()->with('saved', $bookingRoom->fresh()->room === null
            ? __('admin.room_unassigned')
            : __('admin.room_assigned', ['number' => $bookingRoom->fresh()->room->number]));
    }

    public function checkIn(Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->move($booking, BookingStatus::CheckedIn, $bookings, __('admin.checked_in', [
            'name' => $booking->guest->last_name,
        ]));
    }

    public function checkOut(Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->move($booking, BookingStatus::CheckedOut, $bookings, __('admin.checked_out', [
            'name' => $booking->guest->last_name,
        ]));
    }

    /**
     * Answer a late-checkout request, or set a departure time outright.
     *
     * Granting is a separate act from asking, because late checkout is
     * subject to availability on the day: the room may be sold to somebody
     * arriving at three.
     */
    public function departureTime(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'checkout_time' => ['nullable', 'date_format:H:i'],
            'decision' => ['required', Rule::in(['grant', 'decline'])],
        ]);

        if ($validated['decision'] === 'decline') {
            // The request is cleared so the desk stops being asked, and the
            // house time stands.
            $booking->forceFill(['requested_checkout_time' => null])->save();

            return back()->with('saved', __('admin.late_checkout_declined'));
        }

        $granted = $validated['checkout_time'] ?: $booking->requested_checkout_time;

        if ($granted === null) {
            return back()->withErrors(['checkout_time' => __('admin.late_checkout_needs_time')]);
        }

        $booking->forceFill([
            'checkout_time' => $granted,
            'requested_checkout_time' => null,
        ])->save();

        return back()->with('saved', __('admin.late_checkout_granted', ['time' => $granted]));
    }

    protected function move(Booking $booking, BookingStatus $to, BookingService $bookings, string $message): RedirectResponse
    {
        if (! $booking->status->canTransitionTo($to)) {
            // The state machine is the authority (§6); the desk gets told
            // why rather than a 500.
            return back()->with('desk_error', __('admin.cannot_move', [
                'from' => __('booking.status_'.$booking->status->value),
                'to' => __('booking.status_'.$to->value),
            ]));
        }

        $bookings->transition($booking, $to, 'Front desk');

        return back()->with('saved', $message);
    }

    protected function date(Request $request): CarbonImmutable
    {
        $requested = (string) $request->query('date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) === 1
            ? CarbonImmutable::parse($requested)->startOfDay()
            : CarbonImmutable::today();
    }
}
