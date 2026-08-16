<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Availability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ExtendAvailabilityCommand extends Command
{
    protected $signature = 'availability:extend';

    protected $description = 'Pre-generate availability rows through the whole bookable window';

    /**
     * Rows exist for booking_window_days + max_nights + 1 days ahead (§5):
     * a stay starting on the last bookable day still needs rows for its
     * nights AND its checkout date. This over-generation is what makes
     * "missing row = error, never assume available" a safe rule everywhere
     * else in the system.
     */
    public function handle(): int
    {
        $from = CarbonImmutable::today();
        $to = $from->addDays(
            (int) config('doba.booking.booking_window_days')
            + (int) config('doba.booking.max_nights')
            + 1
        );

        $created = 0;

        foreach (RoomType::query()->active()->get() as $roomType) {
            $existing = Availability::query()
                ->where('room_type_id', $roomType->id)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->pluck('date')
                ->map(static fn ($date): string => CarbonImmutable::parse($date)->toDateString())
                ->flip();

            $rows = [];
            $now = now();

            for ($date = $from; $date <= $to; $date = $date->addDay()) {
                if ($existing->has($date->toDateString())) {
                    continue; // never touch a row a hotelier may have edited
                }

                $rows[] = [
                    'room_type_id' => $roomType->id,
                    'date' => $date->toDateString(),
                    'allotment' => $roomType->total_units,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                Availability::query()->insert($chunk);
                $created += count($chunk);
            }
        }

        $this->info("Created {$created} availability rows through {$to->toDateString()}.");

        return self::SUCCESS;
    }
}
