<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Availability\Reconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `php artisan availability:reconcile` (§5, §15).
 *
 * `booked` and `held` are caches of the booking tables. This is the job
 * that proves it, nightly, and says so loudly when it is not true.
 *
 * Both directions are reported, and the quiet one is the reason this
 * exists: overselling announces itself when a guest reaches the desk, but
 * a counter stuck high stops the hotel selling a room it actually has and
 * nothing anywhere looks broken.
 */
class ReconcileAvailabilityCommand extends Command
{
    protected $signature = 'availability:reconcile
                            {--fix : Correct the counters, rather than only reporting}
                            {--days= : How far ahead to check (defaults to the booking window)}';

    protected $description = 'Recompute availability counters from the booking tables and report any drift';

    public function handle(Reconciler $reconciler): int
    {
        $from = CarbonImmutable::today();
        $days = $this->option('days');
        $to = $from->addDays($days === null ? (int) config('doba.booking.booking_window_days', 540) : (int) $days);

        $drift = $reconciler->drift($from, $to);

        if ($drift === []) {
            $this->info('No drift: every counter matches the bookings behind it.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d counter(s) disagree with the bookings behind them:', count($drift)));

        foreach (array_slice($drift, 0, 25) as $entry) {
            $this->line(sprintf(
                '  room type %d · %s · %s: counter %d, actually %d',
                $entry['room_type_id'],
                $entry['date'],
                $entry['column'],
                $entry['counter'],
                $entry['truth'],
            ));
        }

        if (count($drift) > 25) {
            $this->line(sprintf('  … and %d more', count($drift) - 25));
        }

        $fixed = 0;

        if ($this->option('fix')) {
            $fixed = $reconciler->fix($drift);
            $this->info("Corrected {$fixed} row(s) from ground truth.");
        } else {
            $this->line('Run again with --fix to correct them.');
        }

        $reconciler->alert($drift, $this->option('fix'));

        // Non-zero even after fixing. Drift is a bug that already
        // happened; a reconcile that repairs it and exits 0 is a bug
        // nobody ever hears about.
        return self::FAILURE;
    }
}
