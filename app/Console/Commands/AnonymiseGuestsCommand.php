<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Guests\GuestPrivacy;
use Illuminate\Console\Command;

/**
 * The retention clock (§14), turned by the scheduler.
 *
 * Weekly rather than nightly: the obligation is "not longer than
 * needed", not "at midnight sharp", and a weekly cadence makes the log
 * entry rare enough that somebody actually reads it.
 */
class AnonymiseGuestsCommand extends Command
{
    protected $signature = 'doba:guests:anonymise';

    protected $description = 'Anonymise guests whose last stay is beyond the retention window';

    public function handle(GuestPrivacy $privacy): int
    {
        $months = (int) config('doba.privacy.retention_months');

        if ($months <= 0) {
            $this->line('Retention is switched off (DOBA_RETENTION_MONTHS=0).');

            return self::SUCCESS;
        }

        $count = $privacy->anonymiseDue();

        $this->info($count === 0
            ? 'Nobody is beyond the '.$months.'-month window.'
            : 'Anonymised '.$count.' guest(s) beyond the '.$months.'-month window.');

        return self::SUCCESS;
    }
}
