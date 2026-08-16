<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Availability;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Nightly price resolution (§7), first match wins:
 *
 *   1. availability.price for that exact date (manual / channel override)
 *   2. season_rates row matching the date and the weekday bitmask,
 *      highest season priority
 *   3. room_types.default_rate
 *
 * Rate-plan adjustments, occupancy surcharges, extras, promo codes and
 * taxes (§7 steps 4–8) sit on top of this and arrive with the booking
 * funnel — this class answers only "what does this night cost".
 *
 * All seasons are loaded once per instance: a hotel has a handful of
 * seasons, and a two-month calendar must not fire a query per date cell.
 */
class RateResolver
{
    /** @var Collection<int,Season>|null */
    protected ?Collection $seasons = null;

    public function nightlyPrice(RoomType $roomType, CarbonInterface $date, ?Availability $row = null): ?int
    {
        if ($row?->price !== null) {
            return $row->price;
        }

        return $this->seasonPrice($roomType, $date) ?? $roomType->default_rate;
    }

    protected function seasonPrice(RoomType $roomType, CarbonInterface $date): ?int
    {
        foreach ($this->seasons() as $season) {
            if (! $season->containsDate($date)) {
                continue;
            }

            $rate = $season->rates
                ->first(static fn (SeasonRate $rate): bool => $rate->room_type_id === $roomType->id
                    && $rate->matchesWeekday($date));

            if ($rate !== null) {
                return $rate->price;
            }
        }

        return null;
    }

    /**
     * @return Collection<int,Season>
     */
    protected function seasons(): Collection
    {
        return $this->seasons ??= Season::query()
            ->with('rates')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }
}
