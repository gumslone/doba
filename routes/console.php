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
Schedule::command('availability:extend')->dailyAt('02:00');
Schedule::command('doba:sitemap')->dailyAt('03:00');
Schedule::command('doba:images')->dailyAt('03:30');
