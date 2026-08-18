<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Maintenance\Backups;
use App\Support\Maintenance\FreshHealth;
use App\Support\Maintenance\HealthCheck;
use App\Support\Maintenance\Updater;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->directory = storage_path('framework/testing/backups');
    File::deleteDirectory($this->directory);

    $this->backup = new Backups($this->directory);

    // The command and the admin controller resolve Backups from the
    // container; without this they would write to the real backup
    // directory while the test inspected a different one.
    $this->app->instance(Backups::class, $this->backup);

    // Point the uploads disk at a scratch directory. A test that archives
    // and deletes the DEVELOPER'S real storage/app/public destroys the
    // demo photography — which is exactly what happened the first time
    // this suite ran.
    $this->uploads = storage_path('framework/testing/uploads');
    File::deleteDirectory($this->uploads);
    config()->set('filesystems.disks.public.root', $this->uploads);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
    File::deleteDirectory($this->uploads);

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

        // A real install's database carries this table, and EnsureInstalled
        // reads it on every request — without it the scratch database looks
        // like an uninstalled copy and the admin redirects to the wizard.
        DB::connection('backup_source')->statement(
            'create table installations (id integer primary key, steps_completed text, locale text, version text, installed_at datetime, created_at datetime, updated_at datetime)'
        );
        DB::connection('backup_source')->insert(
            "insert into installations (locale, installed_at) values ('en', datetime('now'))"
        );

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

        expect($this->backup->sets())->toHaveCount(12);

        $removed = $this->backup->prune(keep: 10);

        expect($removed)->toBe(2)
            ->and($this->backup->sets())->toHaveCount(10);
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
    $backup = Mockery::mock(Backups::class);
    $backup->shouldReceive('isSupported')->andReturnFalse();
    $backup->shouldReceive('unsupportedReason')->andReturn('mysqldump was not found on this server.');

    $this->app->instance(Backups::class, $backup);

    $exit = Artisan::call('doba:update');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('mysqldump was not found');
});

it('stops before touching anything when the snapshot itself fails', function (): void {
    $backup = Mockery::mock(Backups::class);
    $backup->shouldReceive('isSupported')->andReturnTrue();
    $backup->shouldReceive('create')->andThrow(new RuntimeException('disk full'));

    $result = (new Updater($backup, app(HealthCheck::class), app(FreshHealth::class)))->run();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('disk full')
        ->and(implode(' ', $result->steps))->toContain('nothing was changed')
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
    $this->post('/admin/update/restore', ['stamp' => 'x', 'confirm' => 'x'])->assertRedirect('/admin/login');
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

it('backs up the photos with the database, because half a backup is not one', function (): void {
    onDisk(function (): void {
        File::ensureDirectoryExists($this->uploads.'/rooms');
        File::put($this->uploads.'/rooms/double.jpg', 'not really a jpeg');

        $set = $this->backup->createSet();

        expect($set['database'])->toBeString()
            ->and($set['uploads'])->toBeString()
            ->and($set['uploads_error'])->toBeNull()
            // One timestamp, so the two halves are one backup.
            ->and($this->backup->sets())->toHaveCount(1)
            ->and($this->backup->sets()[0]['uploads'])->toBe($set['uploads']);

        // Restoring the database alone would give a hotel back every
        // booking and a website of broken images.
        $listing = shell_exec('tar -tzf '.escapeshellarg($set['uploads']));

        // The archive's top level is the basename of the configured
        // uploads root, and restore extracts beside it — so it round-trips
        // even on an install that moved its uploads directory.
        expect($listing)->toContain(basename($this->uploads).'/rooms/double.jpg');

        File::deleteDirectory($this->uploads.'/rooms');
    });
});

it('prunes whole sets, never half of one', function (): void {
    onDisk(function (): void {
        File::ensureDirectoryExists($this->uploads.'/rooms');
        File::put($this->uploads.'/rooms/a.jpg', 'x');

        foreach (range(1, 5) as $i) {
            $this->backup->createSet(CarbonImmutable::now()->addSeconds($i));
        }

        expect($this->backup->sets())->toHaveCount(5);

        $this->backup->prune(keep: 2);

        $sets = $this->backup->sets();

        expect($sets)->toHaveCount(2);

        // Every surviving set still has both halves — a database snapshot
        // whose photos were pruned away is not restorable.
        foreach ($sets as $set) {
            expect(file_exists($set['database']))->toBeTrue()
                ->and($set['uploads'])->not->toBeNull()
                ->and(file_exists($set['uploads']))->toBeTrue();
        }

        File::deleteDirectory($this->uploads.'/rooms');
    });
});

it('keeps the database snapshot even when the photos cannot be archived', function (): void {
    onDisk(function (): void {
        // No uploads directory at all — the common case on a fresh install.
        File::deleteDirectory($this->uploads);

        $set = $this->backup->createSet();

        // The database is the half that cannot be rebuilt from anywhere
        // else, so it is never sacrificed to a missing photo directory.
        expect(file_exists($set['database']))->toBeTrue()
            ->and($set['uploads'])->toBeNull();
    });
});

it('will not offer a set that has lost its database as restorable', function (): void {
    onDisk(function (): void {
        $set = $this->backup->createSet();

        File::delete($set['database']);

        // An uploads archive on its own is not something anyone can
        // restore from, so it is not presented as a backup.
        expect($this->backup->sets())->toBeEmpty();
    });
});

it('puts a set back, database and photos together', function (): void {
    onDisk(function (): void {
        File::ensureDirectoryExists($this->uploads.'/rooms');
        File::put($this->uploads.'/rooms/double.jpg', 'original');

        $set = $this->backup->createSet();

        // The world moves on: the photo is replaced and a row is added.
        File::put($this->uploads.'/rooms/double.jpg', 'replaced');
        DB::connection('backup_source')->insert("insert into rooms (code) values ('SGL')");

        expect((int) DB::connection('backup_source')->selectOne('select count(*) c from rooms')->c)->toBe(2);

        expect($this->backup->restore($set['database']))->toBeTrue()
            ->and($this->backup->restoreUploads($set['uploads']))->toBeTrue();

        // Both halves came back, which is the whole point of keeping them
        // as one set.
        expect((int) DB::connection('backup_source')->selectOne('select count(*) c from rooms')->c)->toBe(1)
            ->and(File::get($this->uploads.'/rooms/double.jpg'))->toBe('original');

        File::deleteDirectory($this->uploads.'/rooms');
    });
});

it('takes a fresh backup before restoring, so a mis-click is undoable', function (): void {
    $admin = User::factory()->create();

    onDisk(function () use ($admin): void {
        $this->backup->createSet();
        $stamp = $this->backup->sets()[0]['stamp'];

        $this->actingAs($admin)
            ->post('/admin/update/restore', ['stamp' => $stamp, 'confirm' => $stamp])
            ->assertRedirect('/admin/update');

        // Two sets now: the one that was put back, and a copy of the state
        // it replaced — a restore chosen by mistake is itself undoable.
        expect($this->backup->sets())->toHaveCount(2)
            // And the site is open again, not left closed.
            ->and(app()->isDownForMaintenance())->toBeFalse();
    });
});

it('will not restore at all if the safety copy fails', function (): void {
    $admin = User::factory()->create();

    $backup = Mockery::mock(Backups::class);
    $backup->shouldReceive('sets')->andReturn([]);
    $backup->shouldReceive('find')->andReturn(['stamp' => 's', 'database' => '/tmp/x.sqlite', 'uploads' => null]);
    $backup->shouldReceive('canRestoreDatabase')->andReturnTrue();
    $backup->shouldReceive('createSet')->andThrow(new RuntimeException('disk full'));
    // The one call that must never happen.
    $backup->shouldNotReceive('restore');

    $this->app->instance(Backups::class, $backup);

    $this->actingAs($admin)
        ->post('/admin/update/restore', ['stamp' => 's', 'confirm' => 's'])
        ->assertSessionHas('update_error');

    expect(session('update_error'))->toContain('nothing was restored');
});

it('refuses a restore that is not confirmed by typing the timestamp', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post('/admin/update/restore', ['stamp' => '2026-01-01-000000', 'confirm' => 'yes'])
        ->assertSessionHasErrors('confirm');

    $this->actingAs($admin)
        ->post('/admin/update/restore', ['stamp' => '2026-01-01-000000', 'confirm' => '2026-01-01-000000'])
        ->assertSessionHas('update_error', __('admin.backup_missing'));
});

it('runs a nightly backup that says so loudly when it cannot', function (): void {
    onDisk(function (): void {
        expect(Artisan::call('doba:backup'))->toBe(0);
        expect($this->backup->sets())->toHaveCount(1);
    });

    $broken = Mockery::mock(Backups::class);
    $broken->shouldReceive('isSupported')->andReturnFalse();
    $broken->shouldReceive('unsupportedReason')->andReturn('mysqldump was not found on this server.');
    $this->app->instance(Backups::class, $broken);

    // A backup that silently never runs is worse than one nobody
    // configured — the hotel believes it has copies.
    expect(Artisan::call('doba:backup'))->toBe(1)
        ->and(Artisan::output())->toContain('mysqldump was not found');
});

/*
|--------------------------------------------------------------------------
| Pre-flight and post-flight (§15)
|--------------------------------------------------------------------------
*/

function brokenCheck(string $detail = 'PHP 8.2, and this release needs 8.4.'): array
{
    return [['key' => 'php', 'status' => HealthCheck::CRITICAL, 'label' => 'PHP version', 'detail' => $detail]];
}

function healthyCheck(): array
{
    return [['key' => 'php', 'status' => HealthCheck::OK, 'label' => 'PHP version', 'detail' => 'Fine.']];
}

/**
 * A stand-in for the second process, injected rather than faked.
 *
 * Process::fake() cannot be used here: `config:cache` boots a fresh
 * application internally, which re-points every facade at a new container
 * and takes the fake with it. A dependency held on the object survives
 * that; facade state does not.
 *
 * @param  array<int,array<string,string>>|null  $checks  null = no second process could be started
 */
function stubFresh(?array $checks): FreshHealth
{
    $stub = Mockery::mock(FreshHealth::class);
    $stub->shouldReceive('check')->andReturn($checks);

    return $stub;
}

it('refuses to start on an installation that is not in a state to be updated', function (): void {
    $health = Mockery::mock(HealthCheck::class);
    $health->shouldReceive('all')->andReturn(brokenCheck());

    $backup = Mockery::mock(Backups::class);
    $backup->shouldNotReceive('create');

    $result = (new Updater($backup, $health, stubFresh(null)))->run();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('nothing was changed')
        // The actionable sentence, kept where a hotelier will read it
        // rather than buried in the transcript.
        ->and($result->failedChecks[0]['detail'])->toContain('needs 8.4')
        // And the site was never taken down, because nothing was ever
        // going to happen to it.
        ->and(app()->isDownForMaintenance())->toBeFalse();
});

it('updates anyway when an operator says so out loud', function (): void {
    $health = Mockery::mock(HealthCheck::class);
    $health->shouldReceive('all')->andReturn(brokenCheck());

    $result = (new Updater(app(Backups::class), $health, stubFresh(healthyCheck())))
        ->run(withBackup: false, force: true);

    expect($result->ok)->toBeTrue()
        ->and(implode(' ', $result->steps))->toContain('as asked');
});

it('leaves the site closed when it does not serve after the update', function (): void {
    $health = Mockery::mock(HealthCheck::class);
    $health->shouldReceive('all')->andReturn(healthyCheck());

    $result = (new Updater(app(Backups::class), $health, stubFresh(brokenCheck('Every page returns 500.'))))
        ->run(withBackup: false);

    // A migration reporting success proves the schema changed and nothing
    // else. If the site does not answer afterwards, reopening it would
    // put a broken hotel in front of guests.
    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('not serving')
        ->and($result->failedChecks[0]['detail'])->toContain('500')
        ->and(app()->isDownForMaintenance())->toBeTrue();

    Artisan::call('up');
});

it('says which process verified it, because an in-process check cannot see a fresh cache', function (): void {
    $health = Mockery::mock(HealthCheck::class);
    $health->shouldReceive('all')->andReturn(healthyCheck());

    $verified = (new Updater(app(Backups::class), $health, stubFresh(healthyCheck())))->run(withBackup: false);

    expect($verified->ok)->toBeTrue()
        ->and(implode(' ', $verified->steps))->toContain('fresh process');

    // And when no second process can be started — a shared host with
    // proc_open disabled — it still verifies, and says which of the two
    // weaker answers it got rather than passing one off as the other.
    $fellBack = (new Updater(app(Backups::class), $health, stubFresh(null)))->run(withBackup: false);

    expect($fellBack->ok)->toBeTrue()
        ->and(implode(' ', $fellBack->steps))->toContain('Verified in this process');
});

it('reports an unhealthy installation from the command line', function (): void {
    $health = Mockery::mock(HealthCheck::class);
    $health->shouldReceive('all')->andReturn(brokenCheck());
    app()->instance(HealthCheck::class, $health);

    expect(Artisan::call('doba:health'))->toBe(1)
        ->and(Artisan::output())->toContain('needs 8.4');
});
