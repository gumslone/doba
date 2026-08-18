<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applying an update to a live install (§15, §16).
 *
 * One class behind both entry points — `php artisan doba:update` and the
 * admin's update page — because a hotelier on shared hosting has no shell
 * and must get the *same* sequence, backup included, that an operator with
 * SSH gets. Two implementations of "update the hotel" would eventually
 * differ in exactly the step that matters.
 *
 * The order is deliberate:
 *
 *   0. check the install can serve, and can run this release — before a
 *      single thing is touched
 *   1. snapshot the database
 *   2. close the site — a guest must not meet a half-migrated schema
 *   3. migrate
 *   4. rebuild caches, restart workers
 *   5. check it still serves — in a NEW process, so the caches just
 *      written are actually read
 *   6. reopen, and only then
 *
 * Step 0 exists because an update applied to an install that was already
 * broken cannot be told apart from an update that broke it. Step 5 exists
 * because a migration reporting success proves the schema changed, and
 * nothing else: a route cache pointing at a controller that moved passes
 * every migration and 500s every guest.
 *
 * When step 5 fails the site stays closed. That is the whole trade: a
 * hotel that is closed for maintenance is recoverable in a minute, and a
 * hotel that is open and throwing 500 at everyone looking for a room is
 * not — nobody finds out until the phone stops ringing.
 */
class Updater
{
    public function __construct(
        protected Backups $backup,
        protected HealthCheck $health,
        protected FreshHealth $fresh,
    ) {}

    /**
     * Migrations that exist in the code but not yet in the database.
     *
     * This is what tells a hotelier whether an update is even needed, so
     * it must be answerable without changing anything.
     *
     * @return array<int,string>
     */
    public function pendingMigrations(): array
    {
        // Asked of the migrator rather than scraped out of
        // `migrate:status` output. That output is formatted for humans, so
        // parsing it breaks the first time Laravel restyles a table — and
        // Artisan::output() is a single shared buffer, so a nested call
        // would silently eat the calling command's own output.
        $migrator = app('migrator');
        $repository = $migrator->getRepository();

        $ran = $repository->repositoryExists() ? $repository->getRan() : [];

        $files = $migrator->getMigrationFiles(database_path('migrations'));

        return array_values(array_diff(array_keys($files), $ran));
    }

    public function hasPendingMigrations(): bool
    {
        return $this->pendingMigrations() !== [];
    }

    /**
     * Run the update.
     *
     * @param  bool  $withBackup  false only when the caller has said so out loud
     * @return UpdateResult what happened, step by step
     */
    public function run(bool $withBackup = true, bool $force = false): UpdateResult
    {
        $result = new UpdateResult;
        $pending = $this->pendingMigrations();
        $result->pending = $pending;

        // Before anything is touched. An update is not the moment to
        // discover that this host is a PHP version behind, that storage/
        // went read-only in a hosting migration, or that the site has in
        // fact been down since Tuesday.
        $checks = $this->health->all();
        $failed = HealthCheck::failures($checks);

        if ($failed !== [] && ! $force) {
            $result->failedChecks = $failed;
            $result->fail('This installation is not in a state to be updated, so nothing was changed.');

            foreach ($failed as $check) {
                $result->step($check['label'].': '.$check['detail']);
            }

            return $result;
        }

        if ($failed !== []) {
            $result->step(sprintf('Ignoring %d failed check(s), as asked.', count($failed)));
        }

        $result->step('Pre-flight checks passed.');

        if ($withBackup) {
            try {
                $result->backupPath = $this->backup->create();
                $result->step('Database snapshot taken: '.basename($result->backupPath));
            } catch (Throwable $e) {
                // A failed backup stops the update. The alternative is
                // migrating a hotel's live reservations with no way back,
                // which is the one outcome this whole class exists to
                // prevent.
                $result->fail('Backup failed, so nothing was changed: '.$e->getMessage());

                return $result;
            }
        } else {
            $result->step('Skipping the backup, as asked.');
        }

        $wasDown = app()->isDownForMaintenance();

        if (! $wasDown) {
            Artisan::call('down', ['--render' => 'errors::503', '--retry' => 60]);
            $result->step('Site closed for maintenance.');
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $result->step($pending === []
                ? 'No migrations were pending.'
                : sprintf('Applied %d migration(s).', count($pending)));

            // Caches are rebuilt rather than merely cleared: a shared host
            // serving the first request after an update should not be the
            // thing that compiles every view.
            foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $command) {
                try {
                    Artisan::call($command);
                } catch (Throwable $e) {
                    // A cacheable-route failure must not abort an update
                    // that has already migrated successfully.
                    $result->step("Skipped {$command}: ".$e->getMessage());
                }
            }

            $result->step('Caches rebuilt.');

            Artisan::call('queue:restart');
            $result->step('Queue workers signalled to restart.');

            Artisan::call('storage:link');

            $removed = $this->backup->prune();

            if ($removed > 0) {
                $result->step("Pruned {$removed} old backup(s).");
            }

            // The check that decides whether this hotel reopens.
            $after = $this->verify();
            $result->failedChecks = HealthCheck::failures($after['checks']);

            if ($result->failedChecks !== []) {
                $result->fail('The update ran, but the site is not serving afterwards.');

                foreach ($result->failedChecks as $check) {
                    $result->step($check['label'].': '.$check['detail']);
                }

                $this->recover($result);

                return $result;
            }

            $result->step($after['fresh']
                ? 'Verified in a fresh process: the site serves.'
                : 'Verified in this process: the site serves. (A separate process could not be started, so the rebuilt caches were not re-read — if pages fail, run `php artisan optimize:clear`.)');

            $result->ok = true;
        } catch (Throwable $e) {
            Log::error('Update failed.', ['error' => $e->getMessage()]);

            $result->fail($e->getMessage());

            $this->recover($result);
        } finally {
            if (! $wasDown && $result->reopen) {
                Artisan::call('up');
                $result->step('Site reopened.');
            }
        }

        return $result;
    }

    /**
     * Put things back after a failed migration, as far as is safe.
     *
     * SQLite is restored automatically: the snapshot is a whole-file copy
     * and the file it replaces is the one the failed migration just left
     * half-written. MySQL is not, because restoring over a partially
     * migrated database is a decision with consequences, and an automatic
     * restore that itself fails leaves nobody able to say what state the
     * data is in. There the site stays closed and the operator gets the
     * exact command — a hotel that is down is recoverable; a hotel taking
     * bookings against a broken schema is not.
     */
    /**
     * Ask whether the site serves — from a new process if we can get one.
     *
     * A shared host with proc_open disabled cannot, so this falls back to
     * checking in-process and reports which of the two it managed. The
     * weaker answer is worth having; pretending it is the stronger one is
     * not, because an in-process check cannot see the caches this update
     * just wrote.
     *
     * @return array{checks:array<int,array{key:string,status:string,label:string,detail:string}>,fresh:bool}
     */
    protected function verify(): array
    {
        $checks = $this->fresh->check();

        return $checks === null
            ? ['checks' => $this->health->all(), 'fresh' => false]
            : ['checks' => $checks, 'fresh' => true];
    }

    protected function recover(UpdateResult $result): void
    {
        if ($result->backupPath === null) {
            $result->step('No snapshot was taken, so nothing was restored.');
            $result->reopen = false;

            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite' && $this->backup->restore($result->backupPath)) {
            $result->step('Database restored from the snapshot; the site is unchanged.');

            return;
        }

        $result->reopen = false;
        $result->restoreCommand = $this->backup->restoreHint($result->backupPath);
        $result->step('The site has been left closed. Restore the snapshot before reopening.');
    }
}
