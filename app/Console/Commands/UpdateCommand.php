<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Maintenance\DatabaseBackup;
use App\Support\Maintenance\Updater;
use App\Support\Version;
use Illuminate\Console\Command;

/**
 * `php artisan doba:update` — apply an update to a live install (§15).
 *
 * Everything a deploy needs after the new code is in place: snapshot,
 * close, migrate, rebuild, reopen. The same sequence the admin's update
 * page runs, so an install with SSH and an install without behave
 * identically.
 */
class UpdateCommand extends Command
{
    protected $signature = 'doba:update
                            {--no-backup : Skip the database snapshot (you are on your own)}
                            {--check : Report what an update would do, and change nothing}';

    protected $description = 'Back up, migrate and rebuild caches after deploying new code';

    public function handle(Updater $updater, DatabaseBackup $backup): int
    {
        $this->line('Doba '.Version::current());

        $pending = $updater->pendingMigrations();

        if ($this->option('check')) {
            $this->line($pending === []
                ? 'No migrations pending — this install is up to date.'
                : sprintf('%d migration(s) pending:', count($pending)));

            foreach ($pending as $migration) {
                $this->line('  · '.$migration);
            }

            if (($reason = $backup->unsupportedReason()) !== null) {
                $this->warn('No backup can be taken here: '.$reason);
            }

            return self::SUCCESS;
        }

        $withBackup = ! $this->option('no-backup');

        if ($withBackup && ! $backup->isSupported()) {
            // Refused rather than silently skipped: "update without a
            // backup" has to be something somebody chose.
            $this->error('No backup can be taken here: '.$backup->unsupportedReason());
            $this->line('Re-run with --no-backup if you have a snapshot of your own.');

            return self::FAILURE;
        }

        $result = $updater->run($withBackup);

        foreach ($result->steps as $step) {
            $this->line('  '.$step);
        }

        if (! $result->ok) {
            $this->newLine();
            $this->error('Update failed: '.$result->error);

            if ($result->restoreCommand !== null) {
                $this->newLine();
                $this->warn('The site is still closed. Restore the snapshot with:');
                $this->line('  '.$result->restoreCommand);
                $this->line('then bring it back with: php artisan up');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Updated. Now running Doba '.Version::current());

        return self::SUCCESS;
    }
}
