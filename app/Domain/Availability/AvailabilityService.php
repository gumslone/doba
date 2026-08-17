<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Pricing\RateResolver;
use App\Models\Availability;
use App\Models\RatePlan;
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
    /**
     * Availability rows preloaded for the current operation, keyed by
     * room type id then Y-m-d. Null means "go to the database".
     *
     * @var array<int,Collection<string,Availability>>|null
     */
    protected ?array $primed = null;

    /**
     * Active rate plans preloaded for the current operation, with the room
     * types they are restricted to. Null means "go to the database".
     *
     * @var Collection<int,RatePlan>|null
     */
    protected ?Collection $primedPlans = null;

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
     * Total stay price for one unit, summing resolved nightly prices over N
     * and applying the rate plan's adjustment per night (§7 step 4).
     *
     * Per night, not to the total: a fixed −€10 plan is ten euros off each
     * night, which is what a hotelier means and what the frozen
     * booking_room_nights rows have to add up to.
     *
     * Null when any night has no resolvable price.
     */
    public function stayPrice(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?RatePlan $ratePlan = null,
    ): ?int {
        $nights = $this->nightlyPrices($roomType, $checkIn, $checkOut, $ratePlan);

        return $nights === null ? null : array_sum($nights);
    }

    /**
     * The per-night prices for a stay, keyed by date — the exact numbers
     * frozen into booking_room_nights (§5).
     *
     * @return array<string,int>|null
     */
    public function nightlyPrices(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?RatePlan $ratePlan = null,
    ): ?array {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::instance($checkOut)->startOfDay();

        $rows = $this->rows($roomType, $checkIn, $checkOut->subDay());
        $prices = [];

        for ($date = $checkIn; $date < $checkOut; $date = $date->addDay()) {
            $price = $this->rates->nightlyPrice($roomType, $date, $rows->get($date->toDateString()));

            if ($price === null) {
                return null;
            }

            $prices[$date->toDateString()] = $ratePlan?->adjust($price) ?? $price;
        }

        return $prices;
    }

    /**
     * Every plan sellable for this stay, cheapest first.
     *
     * @return array<int,array{plan:RatePlan,total:int,per_night:int}>
     */
    public function ratePlansFor(
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
    ): array {
        $nights = (int) CarbonImmutable::instance($checkIn)->startOfDay()
            ->diffInDays(CarbonImmutable::instance($checkOut)->startOfDay());

        $offers = [];

        $plans = $this->plansFor($roomType);

        foreach ($plans as $plan) {
            if (! $plan->isEligible($checkIn, $nights)) {
                continue;
            }

            $total = $this->stayPrice($roomType, $checkIn, $checkOut, $plan);

            if ($total === null) {
                continue;
            }

            $offers[] = ['plan' => $plan, 'total' => $total, 'per_night' => (int) round($total / max(1, $nights))];
        }

        usort($offers, static fn (array $a, array $b): int => $a['total'] <=> $b['total']);

        return $offers;
    }

    /**
     * Validate a requested stay against the §6 step-1 rules, returning the
     * lang key of the first problem or null when the dates are usable.
     *
     * Separate from isBookable() because these are *input* errors the guest
     * can fix ("that date is in the past"), not inventory outcomes.
     */
    public function validateStay(CarbonInterface $checkIn, CarbonInterface $checkOut): ?string
    {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::instance($checkOut)->startOfDay();
        $today = CarbonImmutable::today(config('doba.timezone'));

        return match (true) {
            $checkIn >= $checkOut => 'booking.error_range',
            $checkIn < $today => 'booking.error_past',
            $checkIn->diffInDays($checkOut) > (int) config('doba.booking.max_nights') => 'booking.error_too_long',
            $today->diffInDays($checkIn) > (int) config('doba.booking.booking_window_days') => 'booking.error_too_far',
            default => null,
        };
    }

    /**
     * Every room type that can host this stay, with its total price (§6).
     *
     * Returns offers rather than models so the view has nothing left to
     * compute — and so the same shape can feed the future partner API.
     *
     * @return array<int,array{room_type:RoomType,rate_plans:array<int,array{plan:RatePlan,total:int,per_night:int}>,total:int,per_night:int,units_left:int}>
     */
    public function search(
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $adults = 2,
        int $children = 0,
    ): array {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::instance($checkOut)->startOfDay();
        $nights = (int) $checkIn->diffInDays($checkOut);

        $offers = [];

        $roomTypes = RoomType::query()
            ->active()
            ->ordered()
            ->with(['translation', 'translations', 'media', 'amenities.translations'])
            ->get();

        // One query for every room type's nights, instead of one per type
        // per question asked about it.
        $this->prime($roomTypes, $checkIn, $checkOut);

        try {
            foreach ($roomTypes as $roomType) {
                if (! $this->isBookable($roomType, $checkIn, $checkOut, 1, $adults, $children)) {
                    continue;
                }

                $plans = $this->ratePlansFor($roomType, $checkIn, $checkOut);

                // Cheapest eligible plan drives the headline price; the room
                // page shows the full set. With no plans configured at all the
                // base price still sells, so a hotel need not define any.
                $total = $plans === []
                    ? $this->stayPrice($roomType, $checkIn, $checkOut)
                    : $plans[0]['total'];

                if ($total === null) {
                    continue; // no resolvable price is not an offer
                }

                $offers[] = [
                    'room_type' => $roomType,
                    'rate_plans' => $plans,
                    'total' => $total,
                    'per_night' => (int) round($total / max(1, $nights)),
                    // Confirmed bookings only: counting holds would let anyone
                    // with a script manufacture scarcity on the hotel's own
                    // site (§6 step 5).
                    'units_left' => $this->unitsLeft($roomType, $checkIn, $checkOut),
                ];
            }
        } finally {
            // Released whatever happens, so nothing later in the request
            // can read rows that were loaded before somebody booked.
            $this->primed = null;
            $this->primedPlans = null;
        }

        return $offers;
    }

    /**
     * Fewest units free on any night of the stay, ignoring holds.
     */
    protected function unitsLeft(RoomType $roomType, CarbonImmutable $checkIn, CarbonImmutable $checkOut): int
    {
        $rows = $this->rows($roomType, $checkIn, $checkOut->subDay());

        if ($rows->isEmpty()) {
            return 0;
        }

        return (int) $rows
            ->map(static fn (Availability $row): int => max(0, $row->allotment - $row->booked))
            ->min();
    }

    /**
     * The calendar widget payload (§6): one entry per date with exactly
     * what the picker needs to disable cells and show "from" prices.
     *
     * @return array<int,array{date:string,available:bool,price:int|null,min_stay:int,cta:bool,ctd:bool,units_left:int}>
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
                    'units_left' => 0,
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
                // Confirmed bookings only — counting holds would let anyone
                // with a script manufacture scarcity on the hotel's own
                // site (§6 step 5).
                'units_left' => max(0, $row->allotment - $row->booked),
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
        if ($this->primed !== null) {
            return ($this->primed[$roomType->id] ?? collect())
                ->filter(static fn (Availability $row): bool => $row->date->betweenIncluded($from, $to));
        }

        return Availability::query()
            ->where('room_type_id', $roomType->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(static fn (Availability $row): string => $row->date->toDateString());
    }

    /**
     * The active plans sellable for a room type.
     *
     * Served from the primed set during a search, where every type would
     * otherwise run the same two queries: a plan attached to no room type
     * sells for all of them, and one attached to some sells only there.
     *
     * @return Collection<int,RatePlan>
     */
    protected function plansFor(RoomType $roomType): Collection
    {
        if ($this->primedPlans === null) {
            return RatePlan::query()
                ->active()
                ->forRoomType($roomType)
                ->with('translations')
                ->get();
        }

        return $this->primedPlans->filter(
            static fn (RatePlan $plan): bool => $plan->roomTypes->isEmpty()
                || $plan->roomTypes->contains('id', $roomType->id)
        )->values();
    }

    /**
     * Load every room type's nights for one span, once.
     *
     * A search asks each room type the same questions — is it bookable,
     * what does it cost, how many are left — and each of those read the
     * same rows again. At three room types nobody notices; a twenty-room
     * hotel listing rooms individually issued over two hundred queries for
     * one search.
     *
     * Deliberately scoped to a single call rather than memoised for the
     * request: a cache of availability that outlives the operation it was
     * built for is a cache that can be read after somebody's booking has
     * changed it.
     *
     * @param  Collection<int,RoomType>  $roomTypes
     */
    protected function prime(Collection $roomTypes, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $this->primedPlans = RatePlan::query()
            ->active()
            ->with(['translations', 'roomTypes:id'])
            ->get();

        $this->primed = Availability::query()
            ->whereIn('room_type_id', $roomTypes->pluck('id'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('room_type_id')
            ->map(static fn (Collection $rows): Collection => $rows
                ->keyBy(static fn (Availability $row): string => $row->date->toDateString()))
            ->all();
    }
}
