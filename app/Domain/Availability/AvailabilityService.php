<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Pricing\RateResolver;
use App\Models\Availability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The read path of §6.
 *
 * Two date sets, and confusing them is the classic booking-engine bug:
 *
 *   N — the nights sold, [check_in … check_out − 1]: inventory, price and
 *       `closed` apply here.
 *   B — the boundary rows: check_in (CTA, min/max stay) and check_out
 *       (CTD only — never inventory, never `closed`).
 *
 * So every query loads [check_in … check_out] INCLUSIVE: the checkout
 * date's row is needed for closed_to_departure even though no inventory is
 * consumed on it.
 *
 * The write path — holds, lockForUpdate, the booking transaction — builds
 * on the same rows and arrives with the booking funnel.
 */
class AvailabilityService
{
    public function __construct(protected RateResolver $rates) {}

    /**
     * Can this room type host a stay of [checkIn … checkOut) for $units
     * rooms and the given party?
     */
    public function isBookable(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $units = 1,
        ?int $adults = null,
        ?int $children = null,
    ): bool {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::instance($checkOut)->startOfDay();

        if ($checkIn >= $checkOut) {
            return false;
        }

        // Carbon 3 returns a float here; everything downstream compares
        // against integers, and 4 !== 4.0 the strict way.
        $nights = (int) $checkIn->diffInDays($checkOut);

        if ($nights > (int) config('doba.booking.max_nights')) {
            return false;
        }

        if ($adults !== null) {
            $party = $adults + ($children ?? 0);

            if ($party > $roomType->max_occupancy * $units
                || $adults > $roomType->max_adults * $units
                || ($children ?? 0) > $roomType->max_children * $units) {
                return false;
            }
        }

        $rows = $this->rows($roomType, $checkIn, $checkOut);

        // Rows are pre-generated through the whole bookable window, so a
        // missing row is always an error — never "assume available" (§5).
        if ($rows->count() !== $nights + 1) {
            return false;
        }

        /** @var Availability $arrival */
        $arrival = $rows->get($checkIn->toDateString());
        /** @var Availability $departure */
        $departure = $rows->get($checkOut->toDateString());

        // Arrival-row restrictions. Min-stay is evaluated HERE and only
        // here: that is what ARI and every OTA mean by it, and requiring
        // nights >= max(min_stay) across the whole stay would block a
        // Fri–Sun booking that Booking.com accepts (§6).
        if ($arrival->closed_to_arrival
            || $nights < $arrival->min_stay
            || ($arrival->max_stay !== null && $nights > $arrival->max_stay)) {
            return false;
        }

        // Departure row: CTD only. Checking `closed` or allotment here
        // would refuse a checkout on a stop-sell day — the guest is
        // *leaving* that morning.
        if ($departure->closed_to_departure) {
            return false;
        }

        foreach ($rows as $date => $row) {
            if ($date === $checkOut->toDateString()) {
                continue; // boundary row — no inventory consumed
            }

            if ($row->closed || $row->unitsLeft() < $units) {
                return false;
            }

            if ($row->min_stay_through !== null && $nights < $row->min_stay_through) {
                return false;
            }
        }

        return true;
    }

    /**
     * Total stay price for one unit, summing resolved nightly prices over N.
     * Null when any night has no resolvable price.
     */
    public function stayPrice(RoomType $roomType, CarbonInterface $checkIn, CarbonInterface $checkOut): ?int
    {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::instance($checkOut)->startOfDay();

        $rows = $this->rows($roomType, $checkIn, $checkOut->subDay());
        $total = 0;

        for ($date = $checkIn; $date < $checkOut; $date = $date->addDay()) {
            $price = $this->rates->nightlyPrice($roomType, $date, $rows->get($date->toDateString()));

            if ($price === null) {
                return null;
            }

            $total += $price;
        }

        return $total;
    }

    /**
     * The calendar widget payload (§6): one entry per date with exactly
     * what the picker needs to disable cells and show "from" prices.
     *
     * @return array<int,array{date:string,available:bool,price:int|null,min_stay:int,cta:bool,ctd:bool}>
     */
    public function calendar(RoomType $roomType, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->startOfDay();

        $rows = $this->rows($roomType, $from, $to);
        $days = [];

        for ($date = $from; $date <= $to; $date = $date->addDay()) {
            $row = $rows->get($date->toDateString());

            // A missing row is unsellable by definition — the horizon ends
            // where availability:extend stopped generating.
            if ($row === null) {
                $days[] = [
                    'date' => $date->toDateString(),
                    'available' => false,
                    'price' => null,
                    'min_stay' => 1,
                    'cta' => false,
                    'ctd' => false,
                ];

                continue;
            }

            $days[] = [
                'date' => $date->toDateString(),
                'available' => ! $row->closed && $row->unitsLeft() >= 1,
                'price' => $this->rates->nightlyPrice($roomType, $date, $row),
                'min_stay' => $row->min_stay,
                'cta' => $row->closed_to_arrival,
                'ctd' => $row->closed_to_departure,
            ];
        }

        return $days;
    }

    /**
     * Availability rows for [$from … $to] inclusive, keyed by Y-m-d.
     *
     * @return Collection<string,Availability>
     */
    protected function rows(RoomType $roomType, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Availability::query()
            ->where('room_type_id', $roomType->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(static fn (Availability $row): string => $row->date->toDateString());
    }
}
