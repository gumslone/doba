<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A database snapshot taken immediately before an update (§15).
 *
 * The point is not tidiness. An update runs migrations against a hotel's
 * live reservations, and the only difference between "the migration
 * failed" and "the hotel lost its bookings" is whether a copy existed
 * thirty seconds earlier.
 */
class DatabaseBackup
{
    public function __construct(protected string $directory) {}

    public static function make(): self
    {
        return new self(storage_path('app/backups'));
    }

    /**
     * Take a snapshot and return its absolute path.
     */
    public function create(?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now();
        $driver = DB::connection()->getDriverName();

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Cannot create the backup directory [{$this->directory}].");
        }

        return match ($driver) {
            'sqlite' => $this->sqlite($at),
            'mysql', 'mariadb' => $this->mysql($at),
            default => throw new RuntimeException("No backup strategy for the [{$driver}] driver."),
        };
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
        return match (DB::connection()->getDriverName()) {
            'sqlite' => sprintf('cp %s %s', escapeshellarg($path), escapeshellarg((string) config('database.connections.sqlite.database'))),
            default => sprintf(
                'mysql -u%s -p %s < %s',
                escapeshellarg((string) config('database.connections.mysql.username')),
                escapeshellarg((string) config('database.connections.mysql.database')),
                escapeshellarg($path),
            ),
        };
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

        $live = (string) config('database.connections.sqlite.database');

        DB::disconnect();

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
     * Delete all but the newest $keep snapshots.
     *
     * A backup directory that fills a shared-hosting quota takes the site
     * down as surely as a bad migration would.
     *
     * @return int how many were removed
     */
    public function prune(int $keep = 10): int
    {
        $files = glob($this->directory.'/doba-*') ?: [];

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $removed = 0;

        foreach (array_slice($files, max(0, $keep)) as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array<int,array{path:string,size:int,taken_at:CarbonImmutable}>
     */
    public function all(): array
    {
        $files = glob($this->directory.'/doba-*') ?: [];

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return array_map(static fn (string $file): array => [
            'path' => $file,
            'size' => (int) filesize($file),
            'taken_at' => CarbonImmutable::createFromTimestamp(filemtime($file)),
        ], $files);
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
        $config = config('database.connections.'.DB::connection()->getName());

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

    protected function path(CarbonImmutable $at, string $extension): string
    {
        return sprintf('%s/doba-%s.%s', $this->directory, $at->format('Y-m-d-His'), $extension);
    }

    protected function binary(string $name): ?string
    {
        $process = new Process(['which', $name]);
        $process->run();

        $path = trim($process->getOutput());

        return $process->isSuccessful() && $path !== '' ? $path : null;
    }
}
