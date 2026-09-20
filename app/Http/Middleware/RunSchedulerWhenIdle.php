<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Install\Installer;
use App\Support\Scheduling\Heartbeat;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The scheduler for a hotel whose host has no cron (§15).
 *
 * The wizard prints a cron line, and the person this software is for
 * often cannot add one — shared hosting hides it three menus deep, or
 * does not offer it. Without the scheduler, holds never expire and rooms
 * stay blocked by checkouts nobody finished: the site quietly stops
 * selling. So when nothing has run the scheduler for a few minutes, a
 * visitor's request runs it — AFTER the response has gone out, so no
 * guest waits for a backup to finish.
 *
 * A real cron always wins: while it beats, this never fires. And the
 * health check says which of the two is doing the work, because traffic
 * is a poor clock at four in the morning.
 */
class RunSchedulerWhenIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! (bool) config('doba.scheduler.web_fallback', true)) {
            return;
        }

        // `artisan serve` and queue workers are consoles; the test runner
        // is one too, and is the exception.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        $staleAfter = (int) config('doba.scheduler.stale_after', 180);
        $age = Heartbeat::age();

        if ($age !== null && $age < $staleAfter) {
            return;   // cron (or a recent visitor) has it covered
        }

        if (! app(Installer::class)->isInstalled()) {
            return;
        }

        // One runner at a time. flock rather than a cache lock: the file
        // cache store and this share a disk, and an OS lock is released
        // when the process dies, which a cache key is not.
        $lock = @fopen(Heartbeat::path().'.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }

        try {
            ignore_user_abort(true);
            Heartbeat::$viaWeb = true;
            Artisan::call('schedule:run');
        } catch (Throwable $e) {
            Log::warning('Traffic-driven scheduler run failed: '.$e->getMessage());
        } finally {
            Heartbeat::$viaWeb = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
