<?php

declare(strict_types=1);

use App\Support\Maintenance\HealthCheck;
use App\Support\Scheduling\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * The scheduler on a host with no cron (§15): visitor traffic runs it,
 * a real cron always wins, and the health page says which one it is.
 */
function heartbeatWrite(array $data): void
{
    File::put(Heartbeat::path(), json_encode($data));
}

function schedulerCheck(): array
{
    return collect(app(HealthCheck::class)->all(deep: false))->firstWhere('key', 'scheduler');
}

beforeEach(function (): void {
    config()->set('doba.scheduler.web_fallback', true);
    config()->set('doba.locales', ['en']);
});

it('runs the scheduler from a visitor\'s request when nothing else has', function (): void {
    Artisan::shouldReceive('call')->once()->with('schedule:run');

    $this->get('/en')->assertOk();
});

it('stays out of the way while cron is beating', function (): void {
    heartbeatWrite(['at' => CarbonImmutable::now()->getTimestamp() - 30, 'via' => 'cron', 'cron_at' => CarbonImmutable::now()->getTimestamp() - 30]);

    Artisan::shouldReceive('call')->never();

    $this->get('/en')->assertOk();
});

it('steps in again once the last run has gone stale', function (): void {
    heartbeatWrite(['at' => CarbonImmutable::now()->getTimestamp() - 900, 'via' => 'cron', 'cron_at' => CarbonImmutable::now()->getTimestamp() - 900]);

    Artisan::shouldReceive('call')->once()->with('schedule:run');

    $this->get('/en')->assertOk();
});

it('can be switched off, and never runs on an uninstalled copy', function (): void {
    Artisan::shouldReceive('call')->never();

    config()->set('doba.scheduler.web_fallback', false);
    $this->get('/en')->assertOk();

    config()->set('doba.scheduler.web_fallback', true);
    File::delete((string) config('doba.install.lock_path'));
    $this->get('/en');
});

it('records who ran it, and remembers the last real cron through web runs', function (): void {
    Heartbeat::beat();

    expect(Heartbeat::read()['via'])->toBe('cron')
        ->and(Heartbeat::cronAge())->toBeLessThan(5);

    $cronAt = Heartbeat::read()['cron_at'];

    Heartbeat::$viaWeb = true;
    Heartbeat::beat();
    Heartbeat::$viaWeb = false;

    expect(Heartbeat::read()['via'])->toBe('web')
        ->and(Heartbeat::read()['cron_at'])->toBe($cronAt);
});

it('tells the hotelier which clock is running, without blocking an update', function (): void {
    // Nothing at all: holds are not expiring.
    expect(schedulerCheck()['status'])->toBe(HealthCheck::WARNING)
        ->and(schedulerCheck()['detail'])->toContain('Nothing has run the scheduler')
        ->and(schedulerCheck()['detail'])->toContain('php artisan schedule:run');

    // Visitors are doing it.
    $now = CarbonImmutable::now()->getTimestamp();
    heartbeatWrite(['at' => $now - 60, 'via' => 'web', 'cron_at' => null]);

    expect(schedulerCheck()['status'])->toBe(HealthCheck::WARNING)
        ->and(schedulerCheck()['detail'])->toContain('visitor traffic');

    // A real cron.
    heartbeatWrite(['at' => $now - 20, 'via' => 'cron', 'cron_at' => $now - 20]);

    expect(schedulerCheck()['status'])->toBe(HealthCheck::OK);

    // A note, never a blocker: a hotel without cron can still update.
    heartbeatWrite(['at' => $now - 60, 'via' => 'web', 'cron_at' => null]);

    expect(collect(HealthCheck::failures(app(HealthCheck::class)->all(deep: false)))->pluck('key'))->not->toContain('scheduler');
});

it('beats from the schedule itself, first in the list', function (): void {
    $events = app(Schedule::class)->events();

    expect($events[0]->description)->toBe('doba:heartbeat');
});
