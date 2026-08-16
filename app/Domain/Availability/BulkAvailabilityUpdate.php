<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Models\Availability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The bulk editor behind the admin availability grid (§12).
 *
 * "Saturdays in July, min-stay 3, €180" must be one operation, not
 * thirty-one — which is why the weekday mask exists here as well as on
 * season rates. Only fields the hotelier actually filled in are touched:
 * a null means "leave whatever is there", so setting a price across a
 * range cannot silently reset everyone's min-stay to 1.
 */
class BulkAvailabilityUpdate
{
    /**
     * Weekday bits, Monday first — the same ISO convention as SeasonRate,
     * so the two never disagree about which day bit 5 is.
     */
    public const ALL_WEEK = 127;

    /**
     * @param  array<int,int>  $roomTypeIds
     * @param  array{price?:int|null,min_stay?:int|null,max_stay?:int|null,allotment?:int|null,closed?:bool|null,closed_to_arrival?:bool|null,closed_to_departure?:bool|null}  $changes
     * @return array{updated:int,refused:array<int,string>}
     */
    public function apply(
        array $roomTypeIds,
        CarbonInterface $from,
        CarbonInterface $to,
        int $weekdayMask,
        array $changes,
    ): array {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->startOfDay();

        // Only the keys that carry a value: everything else keeps whatever
        // the row already has. Note this must test against null and not
        // truthiness — `closed => false` and `price => 0` are both real
        // instructions, and array_filter's default would drop them.
        $updates = array_filter($changes, static fn ($value): bool => $value !== null);

        if ($updates === [] || $roomTypeIds === []) {
            return ['updated' => 0, 'refused' => []];
        }

        $updated = 0;
        $refused = [];

        DB::transaction(function () use ($roomTypeIds, $from, $to, $weekdayMask, $updates, &$updated, &$refused): void {
            $rows = Availability::query()
                ->whereIn('room_type_id', $roomTypeIds)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->lockForUpdate()
                ->orderBy('room_type_id')
                ->orderBy('date')
                ->get();

            foreach ($rows as $row) {
                if (! $this->matchesWeekday($row->date, $weekdayMask)) {
                    continue;
                }

                // Allotment can never fall below what is already sold or
                // held: the CHECK constraint would refuse the write anyway
                // (§5), but a hotelier deserves to be told which night was
                // the problem rather than shown a driver error.
                if (isset($updates['allotment'])) {
                    $consumed = $row->booked + $row->held;

                    if ((int) $updates['allotment'] < $consumed) {
                        $refused[] = $row->date->toDateString();

                        continue;
                    }
                }

                $row->fill($updates)->save();
                $updated++;
            }
        });

        return ['updated' => $updated, 'refused' => array_values(array_unique($refused))];
    }

    protected function matchesWeekday(CarbonInterface $date, int $mask): bool
    {
        return (bool) ($mask & (1 << ($date->isoWeekday() - 1)));
    }
}
