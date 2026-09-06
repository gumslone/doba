<?php

declare(strict_types=1);

use App\Mail\SystemAlert;
use App\Models\Setting;
use App\Support\Alerts\Alerts;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use App\Support\Maintenance\Backups;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * The second copy of every backup, and the message that says when one
 * did not happen (§15).
 */
beforeEach(function (): void {
    Mail::fake();
    Storage::fake('offsite');

    config()->set('doba.backups.offsite_disk', 'offsite');
    config()->set('doba.backups.offsite_path', 'doba-backups');
    config()->set('doba.backups.offsite_keep', 2);

    Setting::put('contact', 'email', 'desk@hotel.example');
    app(HotelSettings::class)->refresh();
    app(MailSettings::class)->confirm();

    $this->dir = storage_path('framework/testing/backups-'.bin2hex(random_bytes(3)));
    File::ensureDirectoryExists($this->dir);
    $this->backups = new Backups($this->dir);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

it('copies every file of a set offsite and keeps only the newest sets there', function (): void {
    // Three sets over three nights, remote keep = 2.
    foreach ([3, 2, 1] as $daysAgo) {
        $at = CarbonImmutable::now()->subDays($daysAgo);
        $db = sprintf('%s/doba-%s.sqlite', $this->dir, $at->format('Y-m-d-His'));
        $up = sprintf('%s/doba-%s.files.tar.gz', $this->dir, $at->format('Y-m-d-His'));
        file_put_contents($db, 'db');
        file_put_contents($up, 'photos');

        $result = $this->backups->copyOffsite(['database' => $db, 'uploads' => $up, 'uploads_error' => null]);

        expect($result['error'])->toBeNull()
            ->and($result['copied'])->toHaveCount(2);
    }

    $remote = collect(Storage::disk('offsite')->files('doba-backups'));

    // Two sets × two files. The oldest set is gone entirely — never half
    // a set, because a snapshot whose photos vanished is not a backup.
    expect($remote)->toHaveCount(4)
        ->and($remote->filter(fn (string $f): bool => str_contains($f, CarbonImmutable::now()->subDays(3)->format('Y-m-d'))))->toHaveCount(0);
});

it('does nothing offsite when no disk is configured', function (): void {
    config()->set('doba.backups.offsite_disk', '');

    $db = $this->dir.'/doba-2026-01-01-000000.sqlite';
    file_put_contents($db, 'db');

    $result = $this->backups->copyOffsite(['database' => $db, 'uploads' => null, 'uploads_error' => null]);

    expect($result['disk'])->toBeNull()
        ->and(Storage::disk('offsite')->allFiles())->toBe([]);
});

it('mails the hotelier when the offsite copy fails, and keeps the local snapshot', function (): void {
    // A disk that does not exist: the copy cannot happen.
    config()->set('doba.backups.offsite_disk', 'nowhere');

    // The local snapshot is stubbed — a real one refuses inside the test
    // transaction, rightly — so what is exercised is the step after it.
    $db = $this->dir.'/doba-2026-01-01-000000.sqlite';
    file_put_contents($db, 'db');

    $backups = Mockery::mock(Backups::class)->makePartial();
    $backups->shouldReceive('isSupported')->andReturnTrue();
    $backups->shouldReceive('createSet')->andReturn(['database' => $db, 'uploads' => null, 'uploads_error' => null]);
    $backups->shouldReceive('prune')->andReturn(0);
    app()->instance(Backups::class, $backups);

    expect(Artisan::call('doba:backup'))->toBe(0);   // the backup itself succeeded

    Mail::assertQueued(SystemAlert::class, fn (SystemAlert $m): bool => str_contains($m->subjectLine, 'offsite')
        && str_contains(implode(' ', $m->lines), 'nowhere'));

    // The local snapshot exists regardless: the second copy failing is a
    // bad night, not a lost hotel.
    expect(File::exists($db))->toBeTrue();
});

it('says so in the inbox when the backup itself cannot run', function (): void {
    $broken = Mockery::mock(Backups::class);
    $broken->shouldReceive('isSupported')->andReturnFalse();
    $broken->shouldReceive('unsupportedReason')->andReturn('mysqldump was not found');
    app()->instance(Backups::class, $broken);

    Artisan::call('doba:backup');

    Mail::assertQueued(SystemAlert::class, fn (SystemAlert $m): bool => str_contains($m->subjectLine, 'not running')
        && str_contains(implode(' ', $m->lines), 'mysqldump'));
});

it('sends each problem once an hour, and nothing while mail is unconfirmed', function (): void {
    $alerts = app(Alerts::class);

    expect($alerts->send('Something broke', ['detail']))->toBeTrue()
        // The same subject again inside the hour: swallowed, so a queue
        // that fails a thousand jobs produces one message.
        ->and($alerts->send('Something broke', ['detail again']))->toBeFalse()
        ->and($alerts->send('Something else broke', ['x']))->toBeTrue();

    Mail::assertQueued(SystemAlert::class, 2);

    // Unconfirmed mail: an alert about a broken system through a broken
    // mail setup is two silences, not one — it is logged and not sent.
    app(MailSettings::class)->unconfirm();
    Mail::fake();

    expect($alerts->send('A third thing', ['y']))->toBeFalse();
    Mail::assertNothingQueued();
});

it('prefers DOBA_ALERT_EMAIL over the contact address', function (): void {
    config()->set('doba.alerts.email', 'ops@agency.example');

    app(Alerts::class)->send('Routed', ['to the agency']);

    Mail::assertQueued(SystemAlert::class, fn (SystemAlert $m): bool => $m->hasTo('ops@agency.example'));
});

it('alerts when a background job runs out of retries', function (): void {
    $job = new class
    {
        public function resolveName(): string
        {
            return 'App\\Jobs\\DeliverWebhook';
        }
    };

    event(new JobFailed('database', $job, new RuntimeException('endpoint refused')));

    Mail::assertQueued(SystemAlert::class, fn (SystemAlert $m): bool => str_contains($m->subjectLine, 'DeliverWebhook')
        && str_contains(implode(' ', $m->lines), 'endpoint refused'));
});
