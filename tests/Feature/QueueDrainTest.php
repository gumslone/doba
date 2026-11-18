<?php

declare(strict_types=1);

use App\Http\Middleware\DrainQueueAfterResponse;
use App\Mail\TestMessage;
use App\Support\Maintenance\HealthCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

/**
 * Mail is queued, and shared hosting has no queue worker. Without these,
 * a fresh install accepted every booking and never told the guest.
 */
beforeEach(function (): void {
    config(['queue.default' => 'database', 'mail.default' => 'array']);
});

function queuedMail(): void
{
    Mail::to('guest@example.org')->queue(new TestMessage('Hotel Alpenhof', 'ABC123'));
}

it('sends queued mail once the request that queued it has answered', function (): void {
    Route::post('/_test/queue-mail', function () {
        queuedMail();

        return 'ok';
    });

    $this->post('/_test/queue-mail')->assertOk();

    // The middleware's terminate() ran after the response: nothing waits.
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('leaves the queue alone on a page view', function (): void {
    queuedMail();

    $this->get('/en')->assertOk();

    expect(DB::table('jobs')->count())->toBe(1);
});

it('stands aside where an operator says a real worker has the queue', function (): void {
    config(['doba.queue.drain_after_response' => false]);
    queuedMail();

    DrainQueueAfterResponse::shouldDrain()
        ? DrainQueueAfterResponse::drain(5)
        : null;

    expect(DB::table('jobs')->count())->toBe(1);
});

it('retries a mail the server refused instead of failing the request', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    queuedMail();

    DrainQueueAfterResponse::drain(5);   // must not throw

    // Still there, pushed back for a later attempt.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and((int) DB::table('jobs')->value('attempts'))->toBe(1);
});

it('drains the queue from the scheduler too, for retries and delayed work', function (): void {
    // routes/console.php is read at boot, under the suite's sync driver.
    $this->refreshApplication();
    config(['queue.default' => 'database']);
    require base_path('routes/console.php');

    $commands = collect(Schedule::events())->map(fn ($event) => (string) $event->command);

    expect($commands->contains(fn (string $c) => str_contains($c, 'queue:work --stop-when-empty')))->toBeTrue();
});

it('says so on the health page when mail has been waiting', function (): void {
    $check = fn () => collect(app(HealthCheck::class)->all(deep: false))->firstWhere('key', 'queue');

    expect($check()['status'])->toBe(HealthCheck::OK);

    queuedMail();
    DB::table('jobs')->update(['available_at' => time() - 3600, 'created_at' => time() - 3600]);

    expect($check()['status'])->toBe(HealthCheck::WARNING)
        ->and($check()['detail'])->toContain('waiting');
});
