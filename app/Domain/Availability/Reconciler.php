<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Enums\BookingStatus;
use App\Models\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recompute `availability.booked` and `availability.held` from ground
 * truth, and report every disagreement (§5, §15).
 *
 * The counters are caches. The truth is:
 *
 *   booked = one per booking_room per night, for bookings whose status
 *            declares the `booked` side, plus every channel block still
 *            holding its nights
 *   held   = unreleased booking_holds belonging to bookings whose status
 *            declares the `held` side
 *
 * Both directions of drift matter, and the quiet one matters more.
 * Overselling announces itself — a guest arrives and there is no room.
 * A counter stuck *high* silently stops the hotel selling a room it
 * actually has, and nobody notices for a week, because nothing anywhere
 * looks broken.
 *
 * Fixing is safe in a way it would not be elsewhere: these columns are
 * defined as caches of the rows this class counts, so recomputing them
 * cannot lose information. What must never be quiet is that drift
 * happened at all — a cache silently repaired every night is a bug
 * nobody ever hears about.
 */
class Reconciler
{
    /**
     * Compare the counters against ground truth.
     *
     * @return array<int,array{room_type_id:int,date:string,column:string,counter:int,truth:int}>
     */
    public function drift(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $from ??= CarbonImmutable::today();
        $to ??= $from->addDays((int) config('doba.booking.booking_window_days', 540));

        $truth = $this->truth($from, $to);
        $drift = [];

        Availability::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('room_type_id')
            ->orderBy('date')
            ->chunk(2000, function ($rows) use ($truth, &$drift): void {
                foreach ($rows as $row) {
                    $key = $row->room_type_id.'|'.$row->date->toDateString();

                    foreach (['booked', 'held'] as $column) {
                        $actual = (int) ($truth[$column][$key] ?? 0);

                        if ($row->{$column} !== $actual) {
                            $drift[] = [
                                'room_type_id' => $row->room_type_id,
                                'date' => $row->date->toDateString(),
                                'column' => $column,
                                'counter' => $row->{$column},
                                'truth' => $actual,
                            ];
                        }
                    }
                }
            });

        return $drift;
    }

    /**
     * Correct the counters, one room type and month at a time.
     *
     * Chunked and locked rather than one enormous transaction: a hotel
     * taking a booking while the nightly reconcile runs should wait a
     * moment, not wait for every night of the next eighteen months.
     *
     * The truth is re-counted INSIDE the lock. Reusing the numbers from
     * the report would write a figure that was true when the report ran
     * and is not true now — which is how a reconcile invents the very
     * drift it exists to remove.
     *
     * @param  array<int,array{room_type_id:int,date:string,column:string,counter:int,truth:int}>  $drift
     * @return int rows actually changed
     */
    public function fix(array $drift): int
    {
        $fixed = 0;

        $byGroup = [];

        foreach ($drift as $entry) {
            $byGroup[$entry['room_type_id'].'|'.substr($entry['date'], 0, 7)][] = $entry;
        }

        foreach ($byGroup as $group => $entries) {
            [$roomTypeId, $month] = explode('|', $group);

            $dates = array_values(array_unique(array_column($entries, 'date')));
            sort($dates);

            $fixed += DB::transaction(function () use ($roomTypeId, $dates): int {
                $rows = Availability::query()
                    ->where('room_type_id', $roomTypeId)
                    ->whereIn('date', $dates)
                    ->lockForUpdate()
                    ->get();

                $from = CarbonImmutable::parse($dates[0]);
                $to = CarbonImmutable::parse($dates[array_key_last($dates)]);
                $truth = $this->truth($from, $to);

                $changed = 0;

                foreach ($rows as $row) {
                    $key = $row->room_type_id.'|'.$row->date->toDateString();
                    $booked = (int) ($truth['booked'][$key] ?? 0);
                    $held = (int) ($truth['held'][$key] ?? 0);

                    if ($row->booked === $booked && $row->held === $held) {
                        continue;
                    }

                    // The CHECK constraint refuses booked + held > allotment.
                    // Reaching that means the hotel really is oversold and no
                    // arithmetic here can undo it: raise the allotment to what
                    // is genuinely committed and let the alert carry it to a
                    // human, rather than abort the whole reconcile.
                    $allotment = max($row->allotment, $booked + $held);

                    $row->forceFill([
                        'allotment' => $allotment,
                        'booked' => $booked,
                        'held' => $held,
                    ])->save();

                    $changed++;
                }

                return $changed;
            }, attempts: 3);
        }

        return $fixed;
    }

    /**
     * Ground truth for both counters, keyed "roomTypeId|Y-m-d".
     *
     * @return array{booked:array<string,int>, held:array<string,int>}
     */
    protected function truth(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'booked' => $this->bookedTruth($from, $to),
            'held' => $this->heldTruth($from, $to),
        ];
    }

    /**
     * @return array<string,int>
     */
    protected function bookedTruth(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // One row per unit per night is exactly how BookingService writes
        // them, so counting rows counts units.
        $direct = DB::table('booking_room_nights as brn')
            ->join('booking_rooms as br', 'br.id', '=', 'brn.booking_room_id')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereIn('b.status', $this->statusesWithSide('booked'))
            ->whereBetween('brn.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('br.room_type_id', 'brn.date')
            ->selectRaw('br.room_type_id as room_type_id, brn.date as date, count(*) as units')
            ->get();

        $truth = [];

        foreach ($direct as $row) {
            $truth[$row->room_type_id.'|'.$this->date($row->date)] = (int) $row->units;
        }

        // An OTA block occupies the room exactly as a direct booking does
        // and increments the same counter (§9), so it is part of the same
        // truth. Expanded night by night because a channel booking is a
        // range, not a row per night.
        $channel = DB::table('channel_bookings')
            ->whereNull('released_at')
            ->where('check_out', '>', $from->toDateString())
            ->where('check_in', '<=', $to->toDateString())
            ->get(['room_type_id', 'check_in', 'check_out', 'units']);

        foreach ($channel as $block) {
            $night = CarbonImmutable::parse($this->date($block->check_in));
            $end = CarbonImmutable::parse($this->date($block->check_out));

            for (; $night->lt($end); $night = $night->addDay()) {
                if ($night->lt($from) || $night->gt($to)) {
                    continue;
                }

                $key = $block->room_type_id.'|'.$night->toDateString();
                $truth[$key] = ($truth[$key] ?? 0) + (int) $block->units;
            }
        }

        return $truth;
    }

    /**
     * @return array<string,int>
     */
    protected function heldTruth(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Unreleased holds, expired or not: an expired hold still counts
        // in the counter until holds:release retires it, and calling that
        // window "drift" would have the reconcile fight the release
        // command every minute.
        $rows = DB::table('booking_holds as h')
            ->join('bookings as b', 'b.id', '=', 'h.booking_id')
            ->whereNull('h.released_at')
            ->whereIn('b.status', $this->statusesWithSide('held'))
            ->whereBetween('h.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('h.room_type_id', 'h.date')
            ->selectRaw('h.room_type_id as room_type_id, h.date as date, sum(h.units) as units')
            ->get();

        $truth = [];

        foreach ($rows as $row) {
            $truth[$row->room_type_id.'|'.$this->date($row->date)] = (int) $row->units;
        }

        return $truth;
    }

    /**
     * The statuses that declare a given inventory side.
     *
     * Derived from the enum rather than listed here, so a status added
     * later cannot be counted by BookingService and missed by the audit
     * meant to check it.
     *
     * @return array<int,string>
     */
    protected function statusesWithSide(string $side): array
    {
        return array_values(array_map(
            static fn (BookingStatus $status): string => $status->value,
            array_filter(
                BookingStatus::cases(),
                static fn (BookingStatus $status): bool => $status->inventorySide() === $side,
            ),
        ));
    }

    /**
     * MySQL hands back "2026-09-15 00:00:00" where SQLite hands back
     * "2026-09-15"; both have to key the same map.
     */
    protected function date(string $value): string
    {
        return substr($value, 0, 10);
    }

    /**
     * @param  array<int,array<string,mixed>>  $drift
     */
    public function alert(array $drift, bool $fixed): void
    {
        if ($drift === []) {
            return;
        }

        Log::error('Availability drift detected.', [
            'entries' => count($drift),
            'fixed' => $fixed,
            // Enough to find it, not so much that a thousand rows of drift
            // become a log line nobody can read.
            'sample' => array_slice($drift, 0, 20),
        ]);
    }
}
