<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Backups (§15).
 *
 * The point is not tidiness. An update runs migrations against a hotel's
 * live reservations, and the only difference between "the migration
 * failed" and "the hotel lost its bookings" is whether a copy existed
 * thirty seconds earlier.
 *
 * A backup is a SET sharing one timestamp: the database, and — unless
 * switched off — the uploaded photos beside it. The database alone is
 * only half a backup, because restoring it gives a hotel back every
 * booking and a website of broken images. The two are pruned and deleted
 * together for the same reason: half a restore is not a restore.
 */
class Backups
{
    public function __construct(protected string $directory) {}

    public static function make(): self
    {
        return new self(storage_path('app/backups'));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Take a database snapshot and return its absolute path.
     */
    public function create(?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now();
        $driver = DB::connection()->getDriverName();

        $this->ensureDirectory();

        return match ($driver) {
            'sqlite' => $this->sqlite($at),
            'mysql', 'mariadb' => $this->mysql($at),
            default => throw new RuntimeException("No backup strategy for the [{$driver}] driver."),
        };
    }

    /**
     * Take a whole backup: the database, and the uploads beside it.
     *
     * The uploads archive is best-effort — a photo directory that cannot
     * be read must not cost the hotel its database snapshot, which is the
     * half that cannot be reconstructed from anywhere else.
     *
     * @return array{database:string,uploads:string|null,uploads_error:string|null}
     */
    public function createSet(?CarbonImmutable $at = null, ?bool $withUploads = null): array
    {
        $at ??= CarbonImmutable::now();
        $withUploads ??= (bool) config('doba.backups.uploads', true);

        $database = $this->create($at);
        $uploads = null;
        $error = null;

        if ($withUploads) {
            try {
                $uploads = $this->uploads($at);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return ['database' => $database, 'uploads' => $uploads, 'uploads_error' => $error];
    }

    /**
     * Archive the uploaded photos, or null when there are none.
     */
    public function uploads(?CarbonImmutable $at = null): ?string
    {
        $at ??= CarbonImmutable::now();

        // The configured disk root, not a hardcoded storage/app/public: an
        // install that moved its uploads would otherwise get a backup of
        // an empty directory and never be told.
        $source = $this->uploadsRoot();

        if (! is_dir($source) || $this->isEmptyDirectory($source)) {
            return null;
        }

        $this->ensureDirectory();

        $path = $this->path($at, 'files.tar.gz');

        $process = new Process(['tar', '-czf', $path, '-C', dirname($source), basename($source)], timeout: 900);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($path);

            throw new RuntimeException('Could not archive the uploads: '.trim($process->getErrorOutput()));
        }

        return $path;
    }

    /**
     * Can a backup be taken at all on this install?
     *
     * Asked before anything is changed, because "update without a backup"
     * has to be a decision somebody makes on purpose, not something they
     * discover afterwards.
     */
    public function isSupported(): bool
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => true,
            'mysql', 'mariadb' => $this->binary('mysqldump') !== null,
            default => false,
        };
    }

    public function unsupportedReason(): ?string
    {
        if ($this->isSupported()) {
            return null;
        }

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'mysqldump was not found on this server.',
            default => 'This database driver has no backup strategy.',
        };
    }

    /**
     * The command that puts a snapshot back, for a human to run.
     *
     * Printed rather than executed for MySQL: restoring over a
     * half-migrated database is a decision with consequences, and an
     * automatic restore that itself fails leaves nobody able to say what
     * state the data is in.
     */
    public function restoreHint(string $path): string
    {
        // Read from the connection actually in use, not from a connection
        // that happens to be named after the driver: an install whose
        // connection is named anything else would otherwise be told to
        // restore over a database it is not using.
        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'sqlite' => sprintf(
                'cp %s %s',
                escapeshellarg($path),
                escapeshellarg((string) $connection->getConfig('database')),
            ),
            default => sprintf(
                'mysql -u%s -p %s < %s',
                escapeshellarg((string) $connection->getConfig('username')),
                escapeshellarg((string) $connection->getConfig('database')),
                escapeshellarg($path),
            ),
        };
    }

    /**
     * Can this install restore a database snapshot on its own?
     *
     * SQLite can: it is a file copy. MySQL is left to a human, because
     * piping a dump back over a live schema is a decision with
     * consequences and a half-finished restore leaves nobody able to say
     * what state the data is in.
     */
    public function canRestoreDatabase(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * Put a SQLite snapshot back. Safe to automate: it is a file copy, and
     * the file it replaces is the one the failed migration just mangled.
     */
    public function restore(string $path): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || ! is_file($path)) {
            return false;
        }

        $connection = DB::connection();
        $live = (string) $connection->getConfig('database');

        DB::disconnect($connection->getName());

        // The write-ahead log and shared-memory files belong to the old
        // database; leaving them beside a restored file is how a restore
        // produces a database that is neither the backup nor the original.
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($live.$suffix)) {
                @unlink($live.$suffix);
            }
        }

        return copy($path, $live);
    }

    /**
     * Delete all but the newest $keep backup SETS.
     *
     * Counted in sets, not files, so pruning can never leave a database
     * snapshot whose photos have been deleted — half a restore is not a
     * restore. A backup directory that fills a shared-hosting quota takes
     * the site down as surely as a bad migration would.
     *
     * @return int how many sets were removed
     */
    public function prune(?int $keep = null): int
    {
        $keep = max(1, $keep ?? (int) config('doba.backups.keep', 10));
        $removed = 0;

        foreach (array_slice($this->sets(), $keep) as $set) {
            foreach ([$set['database'], $set['uploads']] as $file) {
                if ($file !== null) {
                    @unlink($file);
                }
            }

            $removed++;
        }

        return $removed;
    }

    /**
     * Every backup set, newest first.
     *
     * @return array<int,array{stamp:string,database:string,uploads:string|null,size:int,taken_at:CarbonImmutable}>
     */
    public function sets(): array
    {
        $grouped = [];

        foreach (glob($this->directory.'/doba-*') ?: [] as $file) {
            if (preg_match('/doba-(\d{4}-\d{2}-\d{2}-\d{6})\./', basename($file), $m) !== 1) {
                continue;
            }

            $stamp = $m[1];
            $grouped[$stamp] ??= ['stamp' => $stamp, 'database' => null, 'uploads' => null, 'size' => 0];
            $grouped[$stamp][str_ends_with($file, 'files.tar.gz') ? 'uploads' : 'database'] = $file;
            $grouped[$stamp]['size'] += (int) filesize($file);
        }

        // A stray uploads archive with no database is not a backup anyone
        // can restore from, so it is not offered as one.
        $sets = array_values(array_filter($grouped, static fn (array $set): bool => $set['database'] !== null));

        foreach ($sets as $i => $set) {
            $sets[$i]['taken_at'] = CarbonImmutable::createFromFormat('Y-m-d-His', $set['stamp']);
        }

        usort($sets, static fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));

        return $sets;
    }

    /**
     * @return array{stamp:string,database:string,uploads:string|null,size:int,taken_at:CarbonImmutable}|null
     */
    public function find(string $stamp): ?array
    {
        return collect($this->sets())->firstWhere('stamp', $stamp);
    }

    /**
     * Delete one set, both halves.
     */
    public function forget(string $stamp): bool
    {
        $set = $this->find($stamp);

        if ($set === null) {
            return false;
        }

        foreach ([$set['database'], $set['uploads']] as $file) {
            if ($file !== null) {
                @unlink($file);
            }
        }

        return true;
    }

    /**
     * Put the uploads back from a set's archive.
     *
     * Extracted over the live directory rather than replacing it: a photo
     * uploaded since the backup is not something to delete on the way to
     * restoring an older one.
     */
    public function restoreUploads(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $process = new Process(['tar', '-xzf', $path, '-C', dirname($this->uploadsRoot())], timeout: 900);
        $process->run();

        return $process->isSuccessful();
    }

    public function uploadsRoot(): string
    {
        return (string) config('filesystems.disks.public.root', storage_path('app/public'));
    }

    protected function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Cannot create the backup directory [{$this->directory}].");
        }
    }

    protected function isEmptyDirectory(string $path): bool
    {
        $handle = opendir($path);

        while (($entry = readdir($handle)) !== false) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($handle);

                return false;
            }
        }

        closedir($handle);

        return true;
    }

    protected function sqlite(CarbonImmutable $at): string
    {
        $path = $this->path($at, 'sqlite');

        if (DB::transactionLevel() > 0) {
            // Said plainly, because SQLite's own message ("cannot VACUUM
            // from within a transaction") does not tell the caller what to
            // do about it.
            throw new RuntimeException('A snapshot cannot be taken inside a transaction — commit first.');
        }

        // VACUUM INTO rather than a file copy: it is consistent against a
        // live database with writers mid-transaction, which a cp of a WAL
        // database is emphatically not. Needs SQLite 3.27+; the engine
        // floor here is 3.35.
        DB::statement('VACUUM INTO ?', [$path]);

        return $path;
    }

    protected function mysql(CarbonImmutable $at): string
    {
        $binary = $this->binary('mysqldump');

        if ($binary === null) {
            throw new RuntimeException('mysqldump was not found on this server.');
        }

        $path = $this->path($at, 'sql');
        $config = DB::connection()->getConfig();

        $process = new Process([
            $binary,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            // Single transaction so the dump is consistent without locking
            // the hotel out of taking bookings while it runs.
            '--single-transaction',
            '--quick',
            '--routines',
            '--result-file='.$path,
            $config['database'],
        ], timeout: 600, env: ['MYSQL_PWD' => (string) $config['password']]);

        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($path);

            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }

        return $path;
    }

    /**
     * Where a snapshot goes.
     *
     * Nudged forward a second at a time on collision: the stamp has
     * second resolution, and VACUUM INTO refuses to write over an existing
     * file — so two backups in the same second would fail the second one
     * rather than simply taking two backups.
     */
    protected function path(CarbonImmutable $at, string $extension): string
    {
        for ($i = 0; $i < 60; $i++) {
            $path = sprintf('%s/doba-%s.%s', $this->directory, $at->addSeconds($i)->format('Y-m-d-His'), $extension);

            if (! file_exists($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Could not find a free name for the snapshot.');
    }

    protected function binary(string $name): ?string
    {
        $process = new Process(['which', $name]);
        $process->run();

        $path = trim($process->getOutput());

        return $process->isSuccessful() && $path !== '' ? $path : null;
    }
}
