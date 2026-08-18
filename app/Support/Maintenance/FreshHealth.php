<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * The health check, asked of a brand new process.
 *
 * This exists because of one fact about caches: the process that writes a
 * config or route cache is the one process that will never read it. It
 * already holds its own config and route table in memory, and it will
 * keep using them until it exits. So a route cache pointing at a
 * controller that has moved looks perfectly fine from inside the request
 * that wrote it, and is fatal to every request after it.
 *
 * Checking from a second process is the only way to see what the next
 * visitor will see.
 *
 * Separate from HealthCheck, and injected rather than called statically,
 * for a second reason worth writing down: `config:cache` and
 * `route:cache` boot a *fresh application* internally to read a clean
 * configuration, and booting it re-points every facade at that new
 * container. Anything held in facade state before those commands ran —
 * a binding, a fake — is quietly gone afterwards. A dependency held on
 * `$this` survives; a facade does not.
 */
class FreshHealth
{
    /**
     * @return array<int,array{key:string,status:string,label:string,detail:string}>|null
     *                                                                                    Null when a separate process could not be run at all — a shared
     *                                                                                    host with proc_open disabled, or no PHP binary on the path.
     */
    public function check(): ?array
    {
        try {
            $php = (new PhpExecutableFinder)->find() ?: PHP_BINARY;

            $process = Process::path(base_path())
                ->timeout(60)
                ->run([$php, 'artisan', 'doba:health', '--json']);

            $decoded = json_decode(trim($process->output()), true);

            if (is_array($decoded) && isset($decoded['checks']) && is_array($decoded['checks'])) {
                return $this->normalise($decoded['checks']);
            }

            if ($process->failed()) {
                // It ran and could not answer. That is a failure, not a
                // pass: an update that cannot prove it worked has not
                // proved it worked.
                return [[
                    'key' => 'verify',
                    'status' => HealthCheck::CRITICAL,
                    'label' => 'Post-update check',
                    'detail' => trim($process->errorOutput() ?: $process->output())
                        ?: 'The check could not be run, so the update cannot be confirmed.',
                ]];
            }
        } catch (Throwable) {
            // proc_open disabled, or no PHP binary. The caller falls back
            // to an in-process check and says that is what it did.
        }

        return null;
    }

    /**
     * Take nothing on trust from the other side.
     *
     * That process is running the code this update just installed, which
     * is the whole point of asking it — and also the reason its answer
     * cannot be assumed to have the shape this release expects. A PHP
     * notice printed ahead of the JSON, or a check list that grew a field
     * in the new version, must not turn a successful update into a fatal
     * error inside the verification of it.
     *
     * @param  array<mixed>  $rows
     * @return array<int,array{key:string,status:string,label:string,detail:string}>
     */
    protected function normalise(array $rows): array
    {
        $checks = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $checks[] = [
                'key' => is_scalar($row['key'] ?? null) ? (string) $row['key'] : 'unknown',
                // An unreadable status counts as critical, never as OK.
                // The failure mode of guessing wrong here is reopening a
                // broken hotel.
                'status' => is_scalar($row['status'] ?? null) ? (string) $row['status'] : HealthCheck::CRITICAL,
                'label' => is_scalar($row['label'] ?? null) ? (string) $row['label'] : 'Check',
                'detail' => is_scalar($row['detail'] ?? null) ? (string) $row['detail'] : '',
            ];
        }

        return $checks;
    }
}
