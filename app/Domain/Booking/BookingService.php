<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Pricing\RateResolver;
use App\Enums\BookingStatus;
use App\Mail\BookingConfirmed;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Extra;
use App\Models\Guest;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * The §6 write path. Everything that changes a booking's status or touches
 * inventory goes through here — the web funnel, the admin panel and the
 * future partner API all call these methods, never their own SQL (§17's
 * one rule).
 *
 * Correctness model:
 *  - inventory rows are always mutated under lockForUpdate() inside a
 *    transaction; on MySQL that is row locks, on SQLite the connection's
 *    BEGIN IMMEDIATE serialises the whole transaction
 *  - each status declares its inventory side (held / booked / none) and
 *    transitions diff the sides, so no path can leak a unit
 *  - the CHECK constraint on availability is the last net under all of it
 */
class BookingService
{
    public function __construct(protected RateResolver $rates) {}

    /**
     * Create a hold + pending booking (§6): lock the night rows, verify
     * units, snapshot prices, increment `held`, stamp the hold clock.
     *
     * @param  array<string,mixed>  $guestData  email, first_name, last_name, …
     */
    public function place(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        array $guestData,
        int $adults,
        int $children = 0,
        int $units = 1,
        ?string $sessionId = null,
        ?string $locale = null,
    ): Booking {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::instance($checkOut)->startOfDay();

        if ($checkIn >= $checkOut) {
            throw new InvalidArgumentException('check_in must be before check_out.');
        }

        $nights = (int) $checkIn->diffInDays($checkOut);

        return DB::transaction(function () use (
            $roomType, $checkIn, $checkOut, $nights, $guestData,
            $adults, $children, $units, $sessionId, $locale
        ): Booking {
            // NOTE: nights only — the checkout row consumes no inventory (§6).
            $rows = $this->lockNights($roomType, $checkIn, $checkOut->subDay());

            if ($rows->count() !== $nights) {
                // A missing row is an error, never "assume available" (§5).
                throw new NoAvailabilityException($checkIn->toDateString());
            }

            foreach ($rows as $row) {
                if ($row->closed || $row->unitsLeft() < $units) {
                    throw new NoAvailabilityException($row->date->toDateString());
                }
            }

            $guest = Guest::findOrCreateByEmail((string) $guestData['email'], $guestData);

            $perUnitTotal = 0;
            $nightPrices = [];

            foreach ($rows as $row) {
                $price = $this->rates->nightlyPrice($roomType, $row->date, $row);

                if ($price === null) {
                    throw new NoAvailabilityException($row->date->toDateString());
                }

                $nightPrices[$row->date->toDateString()] = $price;
                $perUnitTotal += $price;
            }

            $subtotal = $perUnitTotal * $units;

            $booking = Booking::create([
                'reference' => Booking::nextReference(),
                'manage_token' => Booking::newManageToken(),
                'status' => BookingStatus::Pending,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $nights,
                'adults' => $adults,
                'children' => $children,
                'currency' => (string) config('doba.currency'),
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'deposit_due' => $subtotal,
                'balance_due' => $subtotal,
                'locale' => $locale ?? app()->getLocale(),
                'guest_id' => $guest->id,
            ]);

            for ($i = 0; $i < $units; $i++) {
                $room = $booking->rooms()->create([
                    'room_type_id' => $roomType->id,
                    'adults' => (int) ceil($adults / $units),
                    'children' => (int) floor($children / $units),
                    'price_total' => $perUnitTotal,
                ]);

                foreach ($nightPrices as $date => $price) {
                    $room->nights()->create(['date' => $date, 'price' => $price]);
                }
            }

            // Increment under the same lock; the CHECK constraint would
            // refuse the write if the verification above ever grew a hole.
            Availability::query()
                ->whereIn('id', $rows->pluck('id'))
                ->increment('held', $units);

            $expiresAt = now()->addMinutes((int) config('doba.booking.hold_minutes'));

            foreach ($rows as $row) {
                $booking->holds()->create([
                    'session_id' => $sessionId,
                    'room_type_id' => $roomType->id,
                    'date' => $row->date,
                    'units' => $units,
                    'expires_at' => $expiresAt,
                ]);
            }

            $this->recordHistory($booking, null, BookingStatus::Pending);

            return $booking;
        }, attempts: 3);
    }

    /**
     * Attach extras to a booking, snapshotting unit price, multiplier and
     * tax rate (§7): an extra's price may change, a taken booking's may not.
     *
     * @param  array<int,int>  $quantities  extra id => quantity
     */
    public function addExtras(Booking $booking, array $quantities): Booking
    {
        return DB::transaction(function () use ($booking, $quantities): Booking {
            $guests = $booking->adults + $booking->children;

            $extras = Extra::query()
                ->active()
                ->whereIn('id', array_keys($quantities))
                ->get();

            foreach ($extras as $extra) {
                $quantity = max(0, min((int) $quantities[$extra->id], $extra->max_quantity));

                if ($quantity < 1 || $extra->is_included) {
                    continue; // included extras are shown, never charged
                }

                $total = $extra->totalFor($booking->nights, $guests, $quantity);

                $booking->extras()->updateOrCreate(['extra_id' => $extra->id], [
                    'quantity' => $quantity,
                    'unit_price' => $extra->price,
                    'total' => $total,
                    'applies_per' => $extra->applies_per,
                    'tax_rate' => $extra->tax_rate,
                ]);
            }

            // Summed from the rows rather than accumulated, so calling this
            // twice cannot double a guest's breakfast — updateOrCreate above
            // makes each call idempotent per extra.
            $extrasTotal = (int) $booking->extras()->sum('total');

            $booking->forceFill([
                'extras_total' => $extrasTotal,
                'total' => $booking->subtotal + $extrasTotal - $booking->discount_total,
            ]);

            $booking->balance_due = $booking->total - $booking->paid_amount;
            $booking->deposit_due = min($booking->deposit_due ?: $booking->total, $booking->total);
            $booking->save();

            return $booking;
        });
    }

    /**
     * Move a booking through the state machine, diffing inventory sides.
     */
    public function transition(Booking $booking, BookingStatus $to, ?string $reason = null, ?int $userId = null): Booking
    {
        $booking = DB::transaction(function () use ($booking, $to, $reason, $userId): Booking {
            // Re-read under the transaction so two staff members clicking
            // at once serialise instead of double-applying.
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $from = $booking->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidArgumentException(
                    "Cannot transition booking {$booking->reference} from {$from->value} to {$to->value}."
                );
            }

            $this->applyInventoryDiff($booking, $from->inventorySide(), $to->inventorySide());

            $booking->status = $to;

            if ($to === BookingStatus::Confirmed) {
                $booking->confirmed_at = now();
                $booking->guest?->increment('stays_count');
                $booking->guest?->increment('total_spent', $booking->total);
            }

            if ($to === BookingStatus::Cancelled) {
                $booking->cancelled_at = now();
                $booking->cancellation_reason = $reason;
            }

            $booking->save();

            $this->recordHistory($booking, $from, $to, $reason, $userId);

            return $booking;
        }, attempts: 3);

        // Sent after the transaction commits, never inside it: a queued job
        // picked up before the commit would find no booking, and a mail
        // failure must not roll back a confirmed booking (§13).
        if ($booking->status === BookingStatus::Confirmed && $booking->guest?->email) {
            Mail::to($booking->guest->email)->queue(new BookingConfirmed($booking));
        }

        return $booking;
    }

    /**
     * held → booked / held → none / booked → none, on locked rows.
     *
     * The held→booked path is what the late payment webhook rides (§6):
     * if the release command already freed the hold, inventory is
     * RE-ACQUIRED under the lock — never trusted to have survived. When it
     * is genuinely gone, NoAvailabilityException tells the caller to
     * refund, not to confirm.
     */
    protected function applyInventoryDiff(Booking $booking, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        // Group per night: multi-room bookings hold >1 unit per date.
        $holds = $booking->holds()->get()->groupBy(static fn (BookingHold $hold): string => $hold->date->toDateString());

        foreach ($holds as $date => $nightHolds) {
            /** @var Availability|null $row */
            $row = Availability::query()
                ->where('room_type_id', $nightHolds->first()->room_type_id)
                ->where('date', $date)
                ->lockForUpdate()
                ->first();

            $live = $nightHolds->whereNull('released_at');
            $liveUnits = (int) $live->sum('units');
            $totalUnits = (int) $nightHolds->sum('units');

            if ($row === null) {
                continue; // horizon has moved past this date; nothing to adjust
            }

            match (true) {
                // pending → confirmed: convert what is still held, re-acquire
                // what the release command already freed.
                $from === 'held' && $to === 'booked' => (function () use ($row, $liveUnits, $totalUnits, $date): void {
                    $reacquire = $totalUnits - $liveUnits;

                    if ($reacquire > 0 && $row->unitsLeft() < $reacquire) {
                        throw new NoAvailabilityException((string) $date);
                    }

                    $row->update([
                        'held' => $row->held - $liveUnits,
                        'booked' => $row->booked + $totalUnits,
                    ]);
                })(),

                // pending → cancelled: release only what is still counted.
                $from === 'held' && $to === 'none' => $row->update(['held' => $row->held - $liveUnits]),

                // confirmed/checked_in/… → cancelled.
                $from === 'booked' && $to === 'none' => $row->update(['booked' => max(0, $row->booked - $totalUnits)]),

                default => throw new InvalidArgumentException("No inventory path {$from} → {$to}."),
            };

            if ($live->isNotEmpty()) {
                BookingHold::query()
                    ->whereIn('id', $live->pluck('id'))
                    ->update(['released_at' => now()]);
            }
        }
    }

    /**
     * @return Collection<int,Availability>
     */
    protected function lockNights(RoomType $roomType, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Availability::query()
            ->where('room_type_id', $roomType->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->lockForUpdate()
            ->get();
    }

    protected function recordHistory(Booking $booking, ?BookingStatus $from, BookingStatus $to, ?string $reason = null, ?int $userId = null): void
    {
        $booking->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
