<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is this hotel actually working? (§15)
 *
 * Written for one moment in particular: the seconds either side of an
 * update. An update applied to an install that was already broken cannot
 * be told apart from an update that broke it, and an update that migrates
 * cleanly can still leave every page returning 500 — a cached route file
 * referencing a controller that moved, a storage directory that went
 * read-only, a PHP version a release now needs and this host does not
 * have.
 *
 * So the same checks run twice. **Before**, to refuse to start on a copy
 * that is not currently serving; and **after** the migrations, to prove
 * the site really answers before it is reopened to guests. A hotel that
 * is closed for maintenance is recoverable. A hotel that is open and
 * throwing 500 at every guest looking for a room is not, and nobody finds
 * out until the phone stops ringing.
 *
 * Every check returns rather than throws, because the report is the point:
 * "your host is on PHP 8.2 and this release needs 8.4" is something a
 * hotelier can act on, and a stack trace is not.
 *
 * @phpstan-type Check array{key:string,status:string,label:string,detail:string}
 */
class HealthCheck
{
    /** Blocking: an update must not start, or must not reopen. */
    public const CRITICAL = 'critical';

    /** Worth saying, not worth stopping for. */
    public const WARNING = 'warning';

    public const OK = 'ok';

    /**
     * Everything, in the order a person would want to read it.
     *
     * @param  bool  $deep  Also make a real request to the site. Skipped in
     *                      the preflight when the site is already closed.
     * @return array<int,Check>
     */
    public function all(bool $deep = true): array
    {
        $checks = [
            $this->php(),
            $this->extensions(),
            $this->writable(),
            $this->appKey(),
            $this->database(),
            $this->schema(),
            $this->diskSpace(),
        ];

        if ($deep) {
            $checks[] = $this->serves();
        }

        return $checks;
    }

    /**
     * @param  array<int,Check>  $checks
     * @return array<int,Check>
     */
    public static function failures(array $checks): array
    {
        return array_values(array_filter($checks, static fn (array $c): bool => $c['status'] === self::CRITICAL));
    }

    /**
     * @param  array<int,Check>  $checks
     */
    public static function passed(array $checks): bool
    {
        return self::failures($checks) === [];
    }

    /**
     * The one that catches an upgrade the host cannot run at all.
     *
     * Read from composer.json rather than hard-coded, so it is the
     * requirement of the code actually sitting on disk — the code that is
     * about to be switched on — and not a number somebody wrote down once.
     *
     * @return Check
     */
    protected function php(): array
    {
        $required = $this->requiredPhp();

        if ($required === null) {
            return $this->result('php', self::WARNING, 'PHP version', 'Could not read the requirement from composer.json.');
        }

        $ok = version_compare(PHP_VERSION, $required, '>=');

        return $this->result(
            'php',
            $ok ? self::OK : self::CRITICAL,
            'PHP version',
            $ok
                ? sprintf('PHP %s (needs %s or newer).', PHP_VERSION, $required)
                : sprintf('This host runs PHP %s, and this release needs %s or newer. Ask your host to switch versions before updating.', PHP_VERSION, $required),
        );
    }

    /**
     * @return Check
     */
    protected function extensions(): array
    {
        $missing = array_values(array_filter(
            $this->requiredExtensions(),
            static fn (string $ext): bool => ! extension_loaded($ext),
        ));

        return $this->result(
            'extensions',
            $missing === [] ? self::OK : self::CRITICAL,
            'PHP extensions',
            $missing === []
                ? 'All required extensions are loaded.'
                : 'Missing: '.implode(', ', $missing).'. The site will not run without them.',
        );
    }

    /**
     * A read-only storage directory is a 500 on every page, and it is the
     * single most common way a hosting migration breaks a Laravel site —
     * the files are all there, the update ran, and nothing renders.
     *
     * @return Check
     */
    protected function writable(): array
    {
        $paths = [
            storage_path('framework/views'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        $bad = array_values(array_filter($paths, static fn (string $p): bool => ! is_dir($p) || ! is_writable($p)));

        return $this->result(
            'writable',
            $bad === [] ? self::OK : self::CRITICAL,
            'Writable directories',
            $bad === []
                ? 'storage/ and bootstrap/cache are writable.'
                : 'Not writable: '.implode(', ', array_map(static fn (string $p): string => str_replace(base_path().'/', '', $p), $bad)),
        );
    }

    /**
     * @return Check
     */
    protected function appKey(): array
    {
        $key = (string) config('app.key');

        return $this->result(
            'app_key',
            $key === '' ? self::CRITICAL : self::OK,
            'Application key',
            $key === ''
                ? 'APP_KEY is empty. Sessions and every encrypted setting will fail.'
                : 'Set.',
        );
    }

    /**
     * @return Check
     */
    protected function database(): array
    {
        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1 as ok');

            return $this->result('database', self::OK, 'Database', sprintf('Connected (%s).', DB::connection()->getDriverName()));
        } catch (Throwable $e) {
            return $this->result('database', self::CRITICAL, 'Database', 'Cannot connect: '.$e->getMessage());
        }
    }

    /**
     * Pending migrations are not a failure — they are the reason to
     * update. They become one only when checked *after* the update.
     *
     * @return Check
     */
    protected function schema(): array
    {
        try {
            $pending = app(Updater::class)->pendingMigrations();
        } catch (Throwable $e) {
            return $this->result('schema', self::CRITICAL, 'Schema', 'Cannot read the migration state: '.$e->getMessage());
        }

        return $this->result(
            'schema',
            $pending === [] ? self::OK : self::WARNING,
            'Schema',
            $pending === []
                ? 'Up to date.'
                : sprintf('%d migration(s) waiting to be applied.', count($pending)),
        );
    }

    /**
     * Enough room for the snapshot the update is about to take.
     *
     * A backup that fails halfway leaves a truncated file that looks like
     * a backup, and the update aborts anyway — better to say so first.
     *
     * @return Check
     */
    protected function diskSpace(): array
    {
        $free = @disk_free_space(storage_path());

        if ($free === false) {
            return $this->result('disk', self::WARNING, 'Disk space', 'Could not be determined on this host.');
        }

        $needed = $this->databaseSize() * 3 + 50 * 1024 * 1024;

        return $this->result(
            'disk',
            $free > $needed ? self::OK : self::CRITICAL,
            'Disk space',
            sprintf(
                '%s free%s',
                $this->human((int) $free),
                $free > $needed ? '.' : sprintf(', which is not enough room for a backup (about %s needed).', $this->human((int) $needed)),
            ),
        );
    }

    /**
     * Does the site actually answer?
     *
     * The check the others cannot replace. Everything above can pass on an
     * install where every page throws — a stale route cache pointing at a
     * controller that moved, a view referencing a variable that no longer
     * exists, a service provider that fails to boot. The only way to know
     * a hotel is serving is to ask it for a page and read the status code.
     *
     * The health endpoint, not the home page: it is exempt from
     * maintenance mode, so this works while the site is closed — which is
     * exactly when the answer matters. A 500 here after a migration means
     * the update failed, whatever the migration said.
     *
     * @return Check
     */
    protected function serves(): array
    {
        try {
            /** @var Kernel $kernel */
            $kernel = app(Kernel::class);

            $response = $kernel->handle(Request::create(url('/up'), 'GET'));
            $status = $response->getStatusCode();

            return $this->result(
                'serves',
                $status < 500 ? self::OK : self::CRITICAL,
                'Site responds',
                $status < 500
                    ? sprintf('The application answered with HTTP %d.', $status)
                    : sprintf('The application answered with HTTP %d. Something is broken beyond the database.', $status),
            );
        } catch (Throwable $e) {
            return $this->result('serves', self::CRITICAL, 'Site responds', 'The application could not handle a request: '.$e->getMessage());
        }
    }

    protected function requiredPhp(): ?string
    {
        $composer = base_path('composer.json');

        if (! is_file($composer)) {
            return null;
        }

        /** @var array<string,mixed> $json */
        $json = json_decode((string) file_get_contents($composer), true) ?: [];
        $constraint = $json['require']['php'] ?? null;

        if (! is_string($constraint)) {
            return null;
        }

        // "^8.4" / ">=8.4" / "8.4.*" all mean the same floor here, and the
        // floor is the only part that can make a host unable to run this.
        return preg_match('/(\d+\.\d+(?:\.\d+)?)/', $constraint, $m) === 1 ? $m[1] : null;
    }

    /**
     * @return array<int,string>
     */
    protected function requiredExtensions(): array
    {
        $composer = base_path('composer.json');

        /** @var array<string,mixed> $json */
        $json = is_file($composer)
            ? (json_decode((string) file_get_contents($composer), true) ?: [])
            : [];

        /** @var array<string,string> $require */
        $require = $json['require'] ?? [];

        $declared = array_values(array_map(
            static fn (string $name): string => substr($name, 4),
            array_filter(array_keys($require), static fn (string $name): bool => str_starts_with($name, 'ext-')),
        ));

        // The ones Doba uses directly whether or not composer.json spells
        // them out: PDO for everything, mbstring for translated content,
        // gd for the photo pipeline, intl for dates in the guest's own
        // language, zip for backups.
        return array_values(array_unique(array_merge($declared, [
            'pdo', 'mbstring', 'json', 'openssl', 'gd', 'intl', 'zip', 'fileinfo',
        ])));
    }

    protected function databaseSize(): int
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $path = (string) config('database.connections.sqlite.database');

            return is_file($path) ? (int) filesize($path) : 0;
        }

        try {
            $row = DB::selectOne(
                'select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = database()'
            );

            return (int) ($row->bytes ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    protected function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return sprintf('%.1f %s', $bytes, $unit);
            }

            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
    }

    /**
     * @return Check
     */
    protected function result(string $key, string $status, string $label, string $detail): array
    {
        return ['key' => $key, 'status' => $status, 'label' => $label, 'detail' => $detail];
    }
}
