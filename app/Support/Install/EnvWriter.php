<?php

declare(strict_types=1);

namespace App\Support\Install;

use RuntimeException;

/**
 * Writes values into a `.env` file (§16).
 *
 * Not `file_put_contents` with concatenation, which is the naive version
 * and produces a broken install with a mystifying error the first time a
 * database password contains a `#` or a space: the `#` starts a comment
 * and the space ends the value, so the app authenticates with half a
 * password and reports that the credentials are wrong.
 *
 * So: existing keys are replaced in place, new keys are appended, and
 * every comment and blank line in between is left exactly where the
 * person who wrote it put it.
 */
class EnvWriter
{
    public function __construct(protected string $path) {}

    public static function make(): self
    {
        return new self(base_path('.env'));
    }

    /**
     * @param  array<string,string|int|bool|null>  $values
     */
    public function write(array $values): void
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("No .env at [{$this->path}].");
        }

        if (! is_writable($this->path)) {
            throw new RuntimeException('The .env file is not writable.');
        }

        $lines = preg_split('/\R/', (string) file_get_contents($this->path)) ?: [];
        $remaining = $values;

        foreach ($lines as $i => $line) {
            // A key line is `KEY=…` with no leading comment marker. Anything
            // else — a comment, a blank, a section banner — is untouched.
            if (preg_match('/^(\s*)([A-Z_][A-Z0-9_]*)\s*=/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches[2];

            if (! array_key_exists($key, $remaining)) {
                continue;
            }

            $lines[$i] = $matches[1].$key.'='.$this->quote($remaining[$key]);
            unset($remaining[$key]);
        }

        if ($remaining !== []) {
            if (end($lines) !== '') {
                $lines[] = '';
            }

            foreach ($remaining as $key => $value) {
                $lines[] = $key.'='.$this->quote($value);
            }
        }

        // Written to a sibling and moved into place: a process that dies
        // mid-write must not leave a hotel with half a .env, which is an
        // application that cannot boot to tell anyone why.
        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        file_put_contents($temporary, implode("\n", $lines));
        chmod($temporary, 0600);

        if (! rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException('Could not replace the .env file.');
        }
    }

    /**
     * Render one value.
     *
     * Quoted whenever it contains anything a shell-style parser treats as
     * special, and quoted values escape backslashes and quotes so the
     * value read back is the value written.
     */
    public function quote(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
