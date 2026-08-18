<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Maintenance\HealthCheck;
use Illuminate\Console\Command;

/**
 * `php artisan doba:health` — is this hotel serving?
 *
 * Also the update's own second opinion. The updater runs this as a
 * **separate process** after rebuilding the caches, because the process
 * that wrote a config or route cache is the one process that will never
 * read it: it already has its own copy in memory. A cached route file
 * pointing at a controller that moved is invisible from inside the
 * request that wrote it, and fatal to every request after.
 */
class HealthCommand extends Command
{
    protected $signature = 'doba:health {--json : Machine-readable, for the updater} {--shallow : Skip the request check}';

    protected $description = 'Check that this installation is able to serve';

    public function handle(HealthCheck $health): int
    {
        $checks = $health->all(deep: ! $this->option('shallow'));
        $ok = HealthCheck::passed($checks);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode(['ok' => $ok, 'checks' => $checks]));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['', 'Check', 'Detail'],
            array_map(static fn (array $c): array => [
                match ($c['status']) {
                    HealthCheck::OK => '<fg=green>OK</>',
                    HealthCheck::WARNING => '<fg=yellow>note</>',
                    default => '<fg=red>FAIL</>',
                },
                $c['label'],
                $c['detail'],
            ], $checks),
        );

        if ($ok) {
            $this->info('This installation is able to serve.');

            return self::SUCCESS;
        }

        $this->error('This installation has problems that will stop it serving. Fix them before updating.');

        return self::FAILURE;
    }
}
