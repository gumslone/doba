<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The queue worker for a host that cannot run one (§15).
 *
 * Mail is queued so that a slow or failing SMTP server can never turn a
 * confirmed booking into an error page. But a queue needs a worker, and
 * the hotels this is built for are on shared hosting, where there is no
 * supervisor and no long-lived process: the confirmation sat in the jobs
 * table and the guest never heard from the hotel.
 *
 * So the request that queued the work drains it — after the response has
 * gone, where the guest is not kept waiting and a failure is retried by
 * the queue instead of being thrown at them. Only requests that change
 * something can have queued anything, so a page view costs nothing. The
 * scheduler drains the same queue every minute for whatever is retried
 * or delayed; a real worker, where one runs, simply gets there first.
 */
class DrainQueueAfterResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethodSafe() || ! self::shouldDrain()) {
            return;
        }

        self::drain(20);
    }

    public static function shouldDrain(): bool
    {
        if (! (bool) config('doba.queue.drain_after_response', true)) {
            return false;
        }

        // sync runs jobs inline; redis and the rest belong to an operator
        // who chose them and runs a worker.
        if (config('queue.default') !== 'database') {
            return false;
        }

        // `artisan serve` and real workers are consoles; the test runner
        // is one too, and is the exception.
        return ! app()->runningInConsole() || app()->runningUnitTests();
    }

    /**
     * Work the queue until it is empty or the time is up.
     */
    public static function drain(int $seconds): void
    {
        try {
            if (! app(Installer::class)->isInstalled()) {
                return;
            }

            $table = (string) config('queue.connections.database.table', 'jobs');

            if (! DB::table($table)->where('available_at', '<=', time())->exists()) {
                return;
            }
        } catch (Throwable) {
            return;   // no jobs table yet: nothing can be waiting in it
        }

        // One drain at a time per install. An OS lock, released when the
        // process dies — a cache key would outlive a killed request.
        $lock = @fopen(storage_path('framework/queue-drain.lock'), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }

        try {
            ignore_user_abort(true);

            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => $seconds,
                '--tries' => 3,
                '--backoff' => 60,
                // The worker sleeps before it notices the queue is empty.
                '--sleep' => 0,
                '--quiet' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning('Draining the queue after the response failed: '.$e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
