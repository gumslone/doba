<?php

declare(strict_types=1);

namespace App\Support\Install;

/**
 * What the server must have before an install can start (§16).
 *
 * Blocking, with no "continue anyway": every hour of support spent on a
 * hotel whose site half-works because `intl` was missing is an hour that
 * this check would have saved, and the hotelier who clicked past the
 * warning is not the one who can diagnose it afterwards.
 *
 * Each failure carries the fix, not just the fact.
 */
class Requirements
{
    public const PHP_FLOOR = '8.4.0';

    /**
     * @return array<int,array{name:string,ok:bool,detail:string,fix:string|null}>
     */
    public function all(): array
    {
        return array_merge($this->php(), $this->extensions(), $this->paths());
    }

    public function satisfied(): bool
    {
        foreach ($this->all() as $check) {
            if (! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int,array{name:string,ok:bool,detail:string,fix:string|null}>
     */
    protected function php(): array
    {
        $ok = version_compare(PHP_VERSION, self::PHP_FLOOR, '>=');

        return [[
            'name' => 'PHP '.self::PHP_FLOOR.'+',
            'ok' => $ok,
            'detail' => PHP_VERSION,
            // Not a preference: SQLite's BEGIN IMMEDIATE — what stops two
            // guests booking the last room at once — only reaches the
            // driver through the framework's PHP >= 8.4 path (§6).
            'fix' => $ok ? null : __('install.fix_php', ['version' => self::PHP_FLOOR]),
        ]];
    }

    /**
     * @return array<int,array{name:string,ok:bool,detail:string,fix:string|null}>
     */
    protected function extensions(): array
    {
        $required = [
            'pdo_sqlite' => 'install.fix_sqlite',
            'mbstring' => 'install.fix_extension',
            'intl' => 'install.fix_extension',
            'gd' => 'install.fix_extension',
            'zip' => 'install.fix_extension',
            'curl' => 'install.fix_extension',
            'openssl' => 'install.fix_extension',
            'fileinfo' => 'install.fix_extension',
        ];

        $checks = [];

        foreach ($required as $extension => $fixKey) {
            $ok = extension_loaded($extension);

            $checks[] = [
                'name' => $extension,
                'ok' => $ok,
                'detail' => $ok ? __('install.loaded') : __('install.missing'),
                'fix' => $ok ? null : __($fixKey, ['extension' => $extension]),
            ];
        }

        // pdo_mysql is not required — a hotel on SQLite needs nothing else
        // — so it is reported rather than demanded.
        $mysql = extension_loaded('pdo_mysql');

        $checks[] = [
            'name' => 'pdo_mysql',
            'ok' => true,
            'detail' => $mysql ? __('install.loaded') : __('install.sqlite_only'),
            'fix' => null,
        ];

        return $checks;
    }

    /**
     * @return array<int,array{name:string,ok:bool,detail:string,fix:string|null}>
     */
    protected function paths(): array
    {
        $checks = [];

        foreach ([base_path('.env'), storage_path(), base_path('bootstrap/cache')] as $path) {
            $ok = is_writable($path);

            $checks[] = [
                'name' => str_replace(base_path().'/', '', $path),
                'ok' => $ok,
                'detail' => $ok ? __('install.writable') : __('install.not_writable'),
                'fix' => $ok ? null : __('install.fix_writable', [
                    'path' => str_replace(base_path().'/', '', $path),
                ]),
            ];
        }

        return $checks;
    }
}
