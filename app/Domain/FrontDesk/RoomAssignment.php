<?php

declare(strict_types=1);

namespace App\Domain\FrontDesk;

use App\Enums\BookingStatus;
use App\Models\BookingRoom;
use App\Models\Room;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Pinning a sold category to a door (§5, phase 2).
 *
 * The one invariant: two parties must never hold the same door on the
 * same night. Everything else here is convenience around that rule.
 */
class RoomAssignment
{
    /**
     * The doors the desk may offer for this stay.
     *
     * Right type, in service, and not holding anyone else during the
     * stay. The stay's OWN current door is included — a reassignment
     * list that hides the current choice looks broken.
     *
     * @return Collection<int,Room>
     */
    public function optionsFor(BookingRoom $bookingRoom): Collection
    {
        $booking = $bookingRoom->booking;

        $busy = Room::query()
            ->occupiedBetween($booking->check_in, $booking->check_out)
            ->when($bookingRoom->room_id !== null, fn ($q) => $q->whereKeyNot($bookingRoom->room_id))
            ->pluck('id')
            ->all();

        return Room::query()
            ->where('room_type_id', $bookingRoom->room_type_id)
            ->where('status', '!=', 'out_of_order')
            ->whereNotIn('id', $busy)
            ->orderBy('number')
            ->get();
    }

    /**
     * Pin the stay to a door, or unpin it with null.
     */
    public function assign(BookingRoom $bookingRoom, ?Room $room): void
    {
        if ($room === null) {
            $bookingRoom->forceFill(['room_id' => null])->save();

            return;
        }

        if ($room->room_type_id !== $bookingRoom->room_type_id) {
            // A free upgrade is a real thing desks do — but it is a
            // pricing and inventory decision, not a dropdown slip. Move
            // the booking to the other category first; the door follows.
            throw new InvalidArgumentException(__('admin.room_wrong_type', ['number' => $room->number]));
        }

        if (! $room->isAssignable()) {
            throw new InvalidArgumentException(__('admin.room_out_of_order', ['number' => $room->number]));
        }

        $booking = $bookingRoom->booking;

        // One query, asked precisely: is any OTHER stay holding this
        // door on any of these nights? (Two separate whereHas clauses
        // would happily match through two different stays.)
        $taken = BookingRoom::query()
            ->whereKeyNot($bookingRoom->id)
            ->where('room_id', $room->id)
            ->whereHas('booking', function ($q) use ($booking): void {
                $q->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::CheckedIn])
                    ->where('check_in', '<', $booking->check_out->toDateString())
                    ->where('check_out', '>', $booking->check_in->toDateString());
            })
            ->exists();

        if ($taken) {
            throw new InvalidArgumentException(__('admin.room_taken', ['number' => $room->number]));
        }

        $bookingRoom->forceFill(['room_id' => $room->id])->save();
    }
}
