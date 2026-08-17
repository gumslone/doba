<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The numbers a hotelier actually looks at (§5).
 *
 * Four decisions decide whether these figures mean anything, and all four
 * are easy to get quietly wrong:
 *
 *  1. **Which bookings count as sold.** The ones whose status declares the
 *     `booked` inventory side — the same rule the booking engine uses, read
 *     from the same enum. A cancelled booking never occupied a room; a
 *     no-show did, in the sense that mattered: nobody else could have it.
 *
 *  2. **Revenue means the room, not the bill.** ADR and RevPAR are room
 *     metrics, and folding breakfast and the spa into them inflates both
 *     and makes them incomparable with any benchmark a hotelier reads.
 *     The frozen per-night prices exclude extras by construction.
 *
 *  3. **Discounts come off.** A promo code takes money off the stay, so
 *     ADR has to fall with it. It is apportioned across that booking's
 *     nights rather than ignored, which is the difference between an ADR
 *     that reconciles with the bank and one that flatters the hotel.
 *
 *  4. **OTA blocks occupy rooms and carry no rate.** An iCal sync knows
 *     the room is gone and nothing about the money. Counting those nights
 *     in occupancy is right — the room really was occupied — but averaging
 *     them into ADR at zero would be a lie. They are counted, reported
 *     separately, and kept out of the rate averages.
 */
class Reports
{
    /**
     * @return array<string,mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $capacity = $this->capacity($from, $to);
        $sold = $this->soldNights($from, $to);
        $revenue = $this->roomRevenue($from, $to);
        $otaNights = $this->otaNights($from, $to);

        $occupiedNights = $sold['nights'] + $otaNights;

        return [
            'from' => $from,
            'to' => $to,
            'capacity' => $capacity,
            'room_nights_sold' => $sold['nights'],
            'ota_nights' => $otaNights,
            'occupied_nights' => $occupiedNights,
            'room_revenue' => $revenue,

            // Occupancy counts every night a room was unavailable to the
            // next guest, OTA blocks included.
            // Cast: PHP hands back an int from an exact division, so an
            // empty month would report occupancy as int 0 and a full one as
            // int 1, while every other month is a float.
            'occupancy' => $capacity > 0 ? (float) ($occupiedNights / $capacity) : 0.0,

            // ADR is averaged only over nights that have a rate. Dividing
            // by nights the channel sync gave us no price for would report
            // a rate the hotel never charged.
            'adr' => $sold['nights'] > 0 ? (int) round($revenue / $sold['nights']) : 0,

            // RevPAR over the whole capacity, which is what makes it
            // comparable — and understated exactly to the extent that OTA
            // nights carry no revenue, which the report says out loud.
            'revpar' => $capacity > 0 ? (int) round($revenue / $capacity) : 0,

            'bookings' => $sold['bookings'],
            'average_stay' => $sold['bookings'] > 0 ? round($sold['nights'] / $sold['bookings'], 1) : 0.0,
        ];
    }

    /**
     * Sellable room-nights in the range.
     *
     * Closed nights are excluded: a room out of order was never available
     * to sell, and counting it drags occupancy down for a decision nobody
     * made about selling.
     */
    public function capacity(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) DB::table('availability')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('closed', false)
            ->sum('allotment');
    }

    /**
     * Room-nights sold and the bookings behind them.
     *
     * @return array{nights:int,bookings:int}
     */
    public function soldNights(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('booking_room_nights as brn')
            ->join('booking_rooms as br', 'br.id', '=', 'brn.booking_room_id')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereIn('b.status', $this->soldStatuses())
            ->whereBetween('brn.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('count(*) as nights, count(distinct b.id) as bookings')
            ->first();

        return [
            'nights' => (int) ($row->nights ?? 0),
            'bookings' => (int) ($row->bookings ?? 0),
        ];
    }

    /**
     * Accommodation revenue for nights in the range, net of discounts.
     *
     * The per-night prices are the frozen ones (§5), so extras are already
     * excluded. The booking's discount is spread across its own nights in
     * proportion to what each night cost — a promo code that took €30 off
     * a stay has to take it off the ADR too, or the report flatters the
     * hotel by exactly the amount it gave away.
     */
    public function roomRevenue(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $gross = (int) DB::table('booking_room_nights as brn')
            ->join('booking_rooms as br', 'br.id', '=', 'brn.booking_room_id')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereIn('b.status', $this->soldStatuses())
            ->whereBetween('brn.date', [$from->toDateString(), $to->toDateString()])
            ->sum('brn.price');

        return max(0, $gross - $this->discountInRange($from, $to));
    }

    /**
     * The part of each discount that belongs to nights inside the range.
     *
     * A stay that straddles the end of a month has its discount split the
     * same way its revenue is, or a month-by-month report stops adding up
     * to the year.
     */
    protected function discountInRange(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $rows = DB::table('bookings as b')
            ->join('booking_rooms as br', 'br.booking_id', '=', 'b.id')
            ->join('booking_room_nights as brn', 'brn.booking_room_id', '=', 'br.id')
            ->whereIn('b.status', $this->soldStatuses())
            ->where('b.discount_total', '>', 0)
            ->groupBy('b.id', 'b.discount_total')
            ->selectRaw(
                'b.id as id, b.discount_total as discount, '
                .'sum(brn.price) as stay_total, '
                .'sum(case when brn.date between ? and ? then brn.price else 0 end) as in_range',
                [$from->toDateString(), $to->toDateString()],
            )
            ->get();

        $apportioned = 0;

        foreach ($rows as $row) {
            $stayTotal = (int) $row->stay_total;

            if ($stayTotal <= 0) {
                continue;
            }

            $apportioned += (int) round((int) $row->discount * (int) $row->in_range / $stayTotal);
        }

        return $apportioned;
    }

    /**
     * Nights held by an OTA block imported over iCal.
     *
     * Occupied, and carrying no rate — a calendar sync says the room is
     * gone and nothing about the money (§9).
     */
    public function otaNights(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $blocks = DB::table('channel_bookings')
            ->whereNull('released_at')
            ->where('check_out', '>', $from->toDateString())
            ->where('check_in', '<=', $to->toDateString())
            ->get(['check_in', 'check_out', 'units']);

        $nights = 0;

        foreach ($blocks as $block) {
            $start = CarbonImmutable::parse(substr((string) $block->check_in, 0, 10))->max($from);
            $end = CarbonImmutable::parse(substr((string) $block->check_out, 0, 10))->min($to->addDay());

            if ($end->lte($start)) {
                continue;
            }

            $nights += (int) $start->diffInDays($end) * (int) $block->units;
        }

        return $nights;
    }

    /**
     * Where the business came from, by room-nights and revenue.
     *
     * @return array<int,array{source:string,nights:int,revenue:int,share:float}>
     */
    public function channelMix(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('booking_room_nights as brn')
            ->join('booking_rooms as br', 'br.id', '=', 'brn.booking_room_id')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereIn('b.status', $this->soldStatuses())
            ->whereBetween('brn.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('b.source')
            ->selectRaw('b.source as source, count(*) as nights, sum(brn.price) as revenue')
            ->get();

        $mix = [];

        foreach ($rows as $row) {
            $mix[] = [
                'source' => (string) $row->source,
                'nights' => (int) $row->nights,
                'revenue' => (int) $row->revenue,
                'share' => 0.0,
            ];
        }

        // iCal blocks are business too, and leaving them out of the mix
        // would make direct look like a larger share of the hotel than it
        // is — the exact number the commission argument turns on.
        $ota = $this->otaNights($from, $to);

        if ($ota > 0) {
            $mix[] = ['source' => 'ical', 'nights' => $ota, 'revenue' => 0, 'share' => 0.0];
        }

        $total = array_sum(array_column($mix, 'nights'));

        foreach ($mix as $i => $entry) {
            $mix[$i]['share'] = $total > 0 ? (float) ($entry['nights'] / $total) : 0.0;
        }

        usort($mix, static fn (array $a, array $b): int => $b['nights'] <=> $a['nights']);

        return $mix;
    }

    /**
     * The same summary month by month.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byMonth(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $cursor = $from->startOfMonth();

        while ($cursor->lte($to)) {
            $start = $cursor->max($from);
            $end = $cursor->endOfMonth()->min($to);

            $months[] = ['month' => $cursor] + $this->summary($start, $end);

            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $months;
    }

    /**
     * How this period compares with the same period a year ago.
     *
     * Compared at the same point in the booking curve, not against the
     * finished year: a hotel in March wants to know whether it is ahead of
     * where it was last March, and measuring today's on-the-books against
     * last year's final total says it is always catastrophically behind.
     *
     * @return array<string,mixed>
     */
    public function pace(CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();

        $now = $this->onTheBooks($from, $to, $asOf);
        $lastYear = $this->onTheBooks($from->subYear(), $to->subYear(), $asOf->subYear());

        return [
            'now' => $now,
            'last_year' => $lastYear,
            'nights_change' => $this->change($lastYear['nights'], $now['nights']),
            'revenue_change' => $this->change($lastYear['revenue'], $now['revenue']),
        ];
    }

    /**
     * Room-nights and revenue for a period, counting only bookings that
     * had been made by a given date.
     *
     * @return array{nights:int,revenue:int}
     */
    protected function onTheBooks(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $asOf): array
    {
        $row = DB::table('booking_room_nights as brn')
            ->join('booking_rooms as br', 'br.id', '=', 'brn.booking_room_id')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereIn('b.status', $this->soldStatuses())
            ->whereBetween('brn.date', [$from->toDateString(), $to->toDateString()])
            ->where('b.created_at', '<=', $asOf->endOfDay())
            ->selectRaw('count(*) as nights, sum(brn.price) as revenue')
            ->first();

        return [
            'nights' => (int) ($row->nights ?? 0),
            'revenue' => (int) ($row->revenue ?? 0),
        ];
    }

    protected function change(int $before, int $after): ?float
    {
        // Null rather than infinity: "up 100%" from nothing is a sentence
        // that reads like growth and means "we had none last year".
        return $before === 0 ? null : (float) (($after - $before) / $before);
    }

    /**
     * @return array<int,string>
     */
    protected function soldStatuses(): array
    {
        return array_values(array_map(
            static fn (BookingStatus $status): string => $status->value,
            array_filter(
                BookingStatus::cases(),
                static fn (BookingStatus $status): bool => $status->inventorySide() === 'booked',
            ),
        ));
    }
}
