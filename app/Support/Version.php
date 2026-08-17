<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which Doba this install is running.
 *
 * Read from a VERSION file written at release time, falling back to the
 * checked-out git description. Both can be absent — a tarball extracted
 * over an old install, say — and "unknown" is a better answer than a
 * fabricated number, because the first thing anybody does with a version
 * string is decide whether they need to update.
 */
final class Version
{
    public static function current(): string
    {
        $file = base_path('VERSION');

        if (is_file($file) && ($contents = trim((string) file_get_contents($file))) !== '') {
            return $contents;
        }

        return self::fromGit() ?? 'unknown';
    }

    protected static function fromGit(): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        $described = @shell_exec('git -C '.escapeshellarg(base_path()).' describe --tags --always --dirty 2>/dev/null');

        $described = is_string($described) ? trim($described) : '';

        return $described !== '' ? $described : null;
    }
}
