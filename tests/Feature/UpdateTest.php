<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Maintenance\DatabaseBackup;
use App\Support\Maintenance\Updater;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->directory = storage_path('framework/testing/backups');
    File::deleteDirectory($this->directory);

    $this->backup = new DatabaseBackup($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);

    // The updater legitimately rebuilds the config, route and view caches
    // — that is the behaviour under test. But a cache written during a
    // test is built from the TEST environment and then sits in
    // bootstrap/cache/ applying to the developer's real install, which
    // silently points artisan at :memory:. The test made the mess; the
    // test clears it.
    Artisan::call('optimize:clear');
});

/**
 * Run a callback against a real on-disk SQLite database.
 *
 * The suite wraps each test in a transaction and runs on :memory:, and
 * VACUUM INTO can do neither — which is the same reason it is the right
 * call in production, where it snapshots a live database consistently
 * without a copy of a half-written WAL.
 */
function onDisk(Closure $callback): void
{
    $file = storage_path('framework/testing/backup-source.sqlite');

    File::ensureDirectoryExists(dirname($file));
    File::put($file, '');

    config()->set('database.connections.backup_source', [
        'driver' => 'sqlite',
        'database' => $file,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    $previous = config('database.default');
    config()->set('database.default', 'backup_source');

    try {
        DB::connection('backup_source')->statement('create table rooms (id integer primary key, code text)');
        DB::connection('backup_source')->insert("insert into rooms (code) values ('DBL')");

        $callback();
    } finally {
        DB::purge('backup_source');
        config()->set('database.default', $previous);
        File::delete($file);
    }
}

it('takes a snapshot that is a working database, not just a file', function (): void {
    onDisk(function (): void {
        $path = $this->backup->create();

        expect(file_exists($path))->toBeTrue()
            ->and(filesize($path))->toBeGreaterThan(0);

        // Openable and populated — a snapshot that is merely present is
        // not a backup.
        $snapshot = new PDO('sqlite:'.$path);

        expect((int) $snapshot->query('select count(*) from rooms')->fetchColumn())->toBe(1);
    });
});

it('says plainly that a snapshot cannot be taken mid-transaction', function (): void {
    // SQLite's own message ("cannot VACUUM from within a transaction")
    // does not tell the caller what to do about it.
    expect(fn () => $this->backup->create())
        ->toThrow(RuntimeException::class, 'commit first');
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'SQLite-only backup path');

it('keeps only the newest snapshots so a shared host does not fill up', function (): void {
    onDisk(function (): void {
        foreach (range(1, 12) as $i) {
            $this->backup->create(CarbonImmutable::now()->addSeconds($i));
        }

        expect($this->backup->all())->toHaveCount(12);

        $removed = $this->backup->prune(keep: 10);

        expect($removed)->toBe(2)
            ->and($this->backup->all())->toHaveCount(10);
    });
});

it('reports pending migrations without changing anything', function (): void {
    $updater = app(Updater::class);

    // The suite migrates before it runs, so nothing is outstanding — and
    // asking must not itself apply anything.
    expect($updater->pendingMigrations())->toBe([])
        ->and($updater->hasPendingMigrations())->toBeFalse();

    Artisan::call('doba:update', ['--check' => true]);

    expect(Artisan::output())->toContain('up to date');
});

it('refuses to update when it cannot take a snapshot first', function (): void {
    // A hotel's live reservations are not migrated on the hope that
    // nothing goes wrong.
    $backup = Mockery::mock(DatabaseBackup::class);
    $backup->shouldReceive('isSupported')->andReturnFalse();
    $backup->shouldReceive('unsupportedReason')->andReturn('mysqldump was not found on this server.');

    $this->app->instance(DatabaseBackup::class, $backup);

    $exit = Artisan::call('doba:update');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('mysqldump was not found');
});

it('stops before touching anything when the snapshot itself fails', function (): void {
    $backup = Mockery::mock(DatabaseBackup::class);
    $backup->shouldReceive('isSupported')->andReturnTrue();
    $backup->shouldReceive('create')->andThrow(new RuntimeException('disk full'));

    $result = (new Updater($backup))->run();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('disk full')
        ->and($result->steps[0])->toContain('nothing was changed')
        // Never taken down, because nothing was ever going to happen.
        ->and(app()->isDownForMaintenance())->toBeFalse();
});

it('reopens the site after a successful update', function (): void {
    $result = app(Updater::class)->run(withBackup: false);

    expect($result->ok)->toBeTrue()
        ->and(app()->isDownForMaintenance())->toBeFalse()
        ->and(implode(' ', $result->steps))
        ->toContain('Site closed')
        ->toContain('Site reopened');
});

it('shows a hotelier what is pending and refuses a mis-click', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get('/admin/update')
        ->assertOk()
        ->assertSee(__('admin.up_to_date'))
        ->assertSee(__('admin.take_backup'));

    // The button migrates a live hotel's reservations, so it takes a typed
    // confirmation rather than a single click.
    $this->actingAs($admin)->post('/admin/update', ['confirm' => 'yes'])
        ->assertSessionHasErrors('confirm');

    $this->actingAs($admin)->post('/admin/update', [])
        ->assertSessionHasErrors('confirm');
});

it('keeps the update page and its backups behind the admin session', function (): void {
    $this->get('/admin/update')->assertRedirect('/admin/login');
    $this->post('/admin/update', ['confirm' => 'UPDATE'])->assertRedirect('/admin/login');

    // The snapshot is the whole database, every guest in it.
    $this->get('/admin/update/backups/doba-2026-01-01-000000.sqlite')->assertRedirect('/admin/login');
});

it('will not serve a file outside the backup directory', function (): void {
    $admin = User::factory()->create();

    foreach ([
        '../../../.env',
        '..%2F..%2F.env',
        'doba-2026-01-01-000000.sqlite',   // well-formed, but no such snapshot
    ] as $name) {
        $this->actingAs($admin)
            ->get('/admin/update/backups/'.$name)
            ->assertNotFound();
    }
});

it('runs the same sequence from the browser as from the shell', function (): void {
    $result = app(Updater::class)->run(withBackup: false);

    // One Updater behind both entry points, so an install with SSH and one
    // without can never diverge in the step that matters.
    expect($result->steps)->toContain('Caches rebuilt.')
        ->and($result->steps)->toContain('Queue workers signalled to restart.');
});
