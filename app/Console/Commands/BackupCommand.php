<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Alerts\Alerts;
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

    public function handle(Backups $backups, Alerts $alerts): int
    {
        if (! $backups->isSupported()) {
            $this->error('No backup can be taken here: '.$backups->unsupportedReason());

            // Loud, because a backup that silently never runs is worse
            // than one that was never configured — the hotel believes it
            // has copies. Loud now means the hotelier's inbox, not a log
            // on a shared host nobody opens.
            $alerts->send('Backups are not running', [
                'The nightly backup could not run: '.$backups->unsupportedReason(),
                'Until this is fixed the hotel has no fresh copy of its bookings.',
            ]);

            return self::FAILURE;
        }

        try {
            $set = $backups->createSet(withUploads: ! $this->option('no-uploads'));
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            $alerts->send('The nightly backup failed', [
                'Error: '.$e->getMessage(),
                'No new snapshot was written tonight.',
            ]);

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

        // The second copy, off this machine. Its failure is not the
        // backup's failure — the local snapshot exists — but it is the
        // difference between a bad night and a lost hotel, so it is said.
        $offsite = $backups->copyOffsite($set);

        if ($offsite['disk'] !== null) {
            if ($offsite['error'] !== null) {
                $this->warn('Offsite:  failed — '.$offsite['error']);
                $alerts->send('The offsite backup copy failed', [
                    'Disk: '.$offsite['disk'],
                    'Error: '.$offsite['error'],
                    'Tonight\'s snapshot exists on this server only.',
                ]);
            } else {
                $this->info('Offsite:  '.count($offsite['copied']).' file(s) copied to ['.$offsite['disk'].']');
            }
        }

        $keep = $this->option('keep');
        $removed = $backups->prune($keep === null ? null : (int) $keep);

        if ($removed > 0) {
            $this->line("Pruned {$removed} old backup set(s).");
        }

        return self::SUCCESS;
    }
}
