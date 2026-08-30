<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| The per-install scheduler (§15). One cron line on the host runs
| `php artisan schedule:run` every minute; everything below hangs off it.
| More entries join as their subsystems land: holds:release (every minute),
| channels:sync (every 15 min), availability:reconcile (nightly), backups.
*/

Schedule::command('holds:release')->everyMinute();
Schedule::command('channels:sync')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('availability:extend')->dailyAt('02:00');

// Proves nightly that `booked` and `held` still match the bookings behind
// them. --fix because these columns are defined as caches of those rows,
// so recomputing them cannot lose anything — but every correction is
// logged at error level, since drift means a bug already happened.
Schedule::command('availability:reconcile --fix')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('doba:sitemap')->dailyAt('03:00');

// On by default. A hotel that never once thought about backups is exactly
// the hotel that needs them; leave DOBA_BACKUP_AT empty to opt out.
if (($backupAt = config('doba.backups.nightly_at')) !== null && $backupAt !== '') {
    Schedule::command('doba:backup')->dailyAt((string) $backupAt)->withoutOverlapping();
}
Schedule::command('doba:images')->dailyAt('03:30');

// Nightly, and only when the hotel has opted in (§21). A property that
// changes its photos and never says so sits in the directory looking like
// it did last year.
Schedule::command('doba:directory:announce')->dailyAt('04:00')->withoutOverlapping();

// Pre-arrival and post-stay mail (§13). After the reconciler and before
// business hours: the balance a pre-arrival mail quotes should be the
// audited one.
Schedule::command('doba:guest-mail')->dailyAt('08:00')->withoutOverlapping();

// The retention clock (§14): weekly, because the obligation is "not
// longer than needed", not "at midnight sharp".
Schedule::command('doba:guests:anonymise')->weeklyOn(1, '04:30')->withoutOverlapping();
