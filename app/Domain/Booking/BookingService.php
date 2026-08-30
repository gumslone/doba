<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Invoicing\InvoiceBuilder;
use App\Domain\Pricing\RateResolver;
use App\Enums\BookingStatus;
use App\Mail\BookingConfirmed;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Extra;
use App\Models\Guest;
use App\Models\PromoCode;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Webhooks\Webhooks;
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
        ?RatePlan $ratePlan = null,
        ?PromoCode $promoCode = null,
    ): Booking {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::instance($checkOut)->startOfDay();

        if ($checkIn >= $checkOut) {
            throw new InvalidArgumentException('check_in must be before check_out.');
        }

        $nights = (int) $checkIn->diffInDays($checkOut);

        $bookingLocale = $locale ?? app()->getLocale();

        $booking = DB::transaction(function () use (
            $roomType, $checkIn, $checkOut, $nights, $guestData,
            $adults, $children, $units, $sessionId, $bookingLocale, $ratePlan, $promoCode
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

                // The plan's adjustment lands here, so the frozen per-night
                // rows already carry the price the guest agreed to (§7).
                $price = $ratePlan?->adjust($price) ?? $price;

                $nightPrices[$row->date->toDateString()] = $price;
                $perUnitTotal += $price;
            }

            $subtotal = $perUnitTotal * $units;

            // Re-read under the transaction's lock: the code was checked
            // when the guest typed it, but "50 uses" means 50, and two
            // checkouts finishing in the same second must not both be the
            // fiftieth. Re-validating here also catches a code deactivated
            // while the guest was filling in the form.
            $discount = 0;

            if ($promoCode !== null) {
                $promoCode = PromoCode::query()->lockForUpdate()->find($promoCode->id);

                $rejection = $promoCode?->rejectionReason(
                    $checkIn, $nights, $subtotal, [$roomType->id], $guest,
                );

                if ($promoCode === null || $rejection !== null) {
                    throw new PromoCodeException($rejection ?? 'promo.error_invalid');
                }

                $discount = $promoCode->discountFor($nightPrices, $units);
            }

            // Per PERSON per night, not per room (§7): the municipality
            // taxes the sleeper, and the invoice must show it on its own
            // line. Deliberately outside the deposit — the deposit
            // secures the room, and the tax is settled with the stay.
            $cityTax = self::cityTax($adults, $children, $nights);

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
                'discount_total' => $discount,
                'promo_code_id' => $promoCode?->id,
                'city_tax' => $cityTax,
                'total' => $subtotal - $discount + $cityTax,
                'deposit_due' => $subtotal - $discount,
                'balance_due' => $subtotal - $discount + $cityTax,
                'locale' => $bookingLocale,
                'guest_id' => $guest->id,
            ]);

            for ($i = 0; $i < $units; $i++) {
                $room = $booking->rooms()->create([
                    'room_type_id' => $roomType->id,
                    'rate_plan_id' => $ratePlan?->id,
                    'adults' => (int) ceil($adults / $units),
                    'children' => (int) floor($children / $units),
                    'price_total' => $perUnitTotal,
                    // Frozen in the guest's own language at booking time.
                    // A dispute is settled by the wording they agreed to,
                    // not by today's version of the policy — and it lives
                    // per room because a booking may mix plans (§7).
                    'cancellation_policy_snapshot' => $ratePlan?->t('policy_text', $bookingLocale),
                    'cancellation_hours_snapshot' => $ratePlan?->cancellation_hours,
                    // No plan configured at all means a refundable booking:
                    // a hotel that never defined its terms cannot be said
                    // to have sold a non-refundable stay.
                    'refundable_snapshot' => $ratePlan === null || $ratePlan->refundable,
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

            if ($promoCode !== null) {
                $booking->redemption()->create([
                    'promo_code_id' => $promoCode->id,
                    'guest_id' => $guest->id,
                    'amount' => $discount,
                    'redeemed_at' => now(),
                ]);

                $promoCode->increment('usage_count');
            }

            $this->recordHistory($booking, null, BookingStatus::Pending);

            return $booking;
        }, attempts: 3);

        app(Webhooks::class)->emit('booking.created', $this->webhookPayload($booking));

        return $booking;
    }

    /**
     * What a partner is told when a booking changes.
     *
     * Deliberately small, and deliberately carrying `updated_at`:
     * delivery is at-least-once and can arrive out of order, so a
     * receiver has to be able to tell a stale event from a current one
     * (§17). A partner that ignores it will eventually resurrect a
     * cancelled booking.
     *
     * @return array<string,mixed>
     */
    protected function webhookPayload(Booking $booking): array
    {
        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'total' => ['amount' => $booking->total, 'currency' => $booking->currency],
            'updated_at' => $booking->updated_at?->toIso8601String(),
        ];
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
                // City tax belongs in the total because the invoice bills
                // it: a booking whose total omits a line the invoice prints
                // is a document that demands more than the guest agreed to.
                'total' => $booking->subtotal + $extrasTotal + $booking->city_tax - $booking->discount_total,
            ]);

            $booking->balance_due = $booking->total - $booking->paid_amount;
            $booking->deposit_due = min($booking->deposit_due ?: $booking->total, $booking->total);
            $booking->save();

            return $booking;
        });
    }

    /**
     * What a cancellation right now would refund (§7).
     *
     * Computed entirely from the SNAPSHOT on each booking room, never from
     * the live rate plan: the guest is owed what they agreed to, and the
     * hotelier may have edited the plan since. Room nights and extras are
     * treated alike — a cancelled stay does not owe breakfast.
     */
    public function refundableAmount(Booking $booking, ?CarbonInterface $at = null): int
    {
        $at = CarbonImmutable::instance($at ?? now());
        $refundable = 0;

        foreach ($booking->rooms as $room) {
            if (! $room->refundable_snapshot) {
                continue; // the saver rate, forfeited by design
            }

            $deadline = $booking->check_in
                ->startOfDay()
                ->subHours($room->cancellation_hours_snapshot ?? 0);

            if ($at->lte($deadline)) {
                $refundable += $room->price_total;
            }
        }

        // Extras follow the rooms: if anything is still inside its free
        // window, the extras attached to the stay come back too.
        if ($refundable > 0) {
            $refundable += (int) $booking->extras()->sum('total');
        }

        // Never more than the guest actually paid — a partial payment
        // cannot become a profit centre.
        return min($refundable, $booking->paid_amount);
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

            // Stamped here rather than derived from the history: the desk
            // asks "who is in the house right now" on every page load.
            if ($to === BookingStatus::CheckedIn) {
                $booking->checked_in_at = CarbonImmutable::now();
            }

            if ($to === BookingStatus::CheckedOut) {
                $booking->checked_out_at = CarbonImmutable::now();

                // The door goes on housekeeping's list the moment the
                // guest leaves it. Only clean turns dirty: a door that is
                // out_of_order has a bigger problem than the sheets.
                Room::query()
                    ->whereIn('id', $booking->rooms()->whereNotNull('room_id')->pluck('room_id'))
                    ->where('status', 'clean')
                    ->update(['status' => 'dirty']);
            }

            if ($to === BookingStatus::Cancelled) {
                $booking->cancelled_at = now();
                $booking->cancellation_reason = $reason;

                // Give the code's use back. An abandoned checkout must not
                // burn a redemption: a hundred expired holds would retire a
                // campaign for no reason the hotelier could ever see.
                $redemption = $booking->redemption()->whereNull('released_at')->first();

                if ($redemption !== null) {
                    $redemption->forceFill(['released_at' => now()])->save();
                    $redemption->promoCode?->decrement('usage_count');
                }
            }

            $booking->save();

            $this->recordHistory($booking, $from, $to, $reason, $userId);

            return $booking;
        }, attempts: 3);

        // Both of these run AFTER the transaction commits, never inside
        // it: a queued job picked up before the commit would find no
        // booking, and neither a mail nor an invoice failure may roll back
        // a confirmed, paid stay (§13).
        // Emitted after the commit, like the mail: a partner that reacts
        // by calling back must find the booking in the state we told them
        // about (§13).
        app(Webhooks::class)->emit(
            $to === BookingStatus::Cancelled ? 'booking.cancelled' : 'booking.updated',
            $this->webhookPayload($booking),
        );

        if ($booking->status === BookingStatus::Confirmed) {
            // The invoice is issued first so the mail can carry it.
            app(InvoiceBuilder::class)->issue($booking);

            if ($booking->guest?->email) {
                Mail::to($booking->guest->email)->queue(new BookingConfirmed($booking));
            }
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
     * The municipal visitor's tax for a stay, in minor units.
     */
    public static function cityTax(int $adults, int $children, int $nights): int
    {
        $rate = (int) config('doba.taxes.city_tax_per_person_night', 0);

        if ($rate <= 0) {
            return 0;
        }

        $people = $adults + (config('doba.taxes.city_tax_children_exempt', true) ? 0 : $children);

        return $rate * $people * $nights;
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
