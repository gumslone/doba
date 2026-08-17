<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Maintenance\Backups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan doba:backup` — the nightly backup (§15).
 *
 * Scheduled by default. A hotel that has never once thought about backups
 * is exactly the hotel that needs them, and "the hotelier will set this up"
 * is not a plan.
 */
class BackupCommand extends Command
{
    protected $signature = 'doba:backup
                            {--keep= : How many sets to keep (defaults to doba.backups.keep)}
                            {--no-uploads : Database only, skip the photos}';

    protected $description = 'Back up the database and the uploaded photos';

    public function handle(Backups $backups): int
    {
        if (! $backups->isSupported()) {
            $this->error('No backup can be taken here: '.$backups->unsupportedReason());

            // Loud, because a backup that silently never runs is worse
            // than one that was never configured — the hotel believes it
            // has copies.
            Log::error('Scheduled backup could not run.', ['reason' => $backups->unsupportedReason()]);

            return self::FAILURE;
        }

        try {
            $set = $backups->createSet(withUploads: ! $this->option('no-uploads'));
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            Log::error('Scheduled backup failed.', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info('Database: '.basename($set['database']));

        if ($set['uploads'] !== null) {
            $this->info('Uploads:  '.basename($set['uploads']));
        } elseif ($set['uploads_error'] !== null) {
            // Not fatal — the database is the half that cannot be rebuilt
            // from anywhere else — but never silent.
            $this->warn('Uploads:  failed — '.$set['uploads_error']);
            Log::warning('Upload archive failed during backup.', ['error' => $set['uploads_error']]);
        }

        $keep = $this->option('keep');
        $removed = $backups->prune($keep === null ? null : (int) $keep);

        if ($removed > 0) {
            $this->line("Pruned {$removed} old backup set(s).");
        }

        return self::SUCCESS;
    }
}
