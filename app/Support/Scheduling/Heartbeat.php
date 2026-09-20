<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Proof that the scheduler is alive, and who is keeping it alive (§15).
 *
 * A file rather than the cache or the database: it is read on every web
 * request to decide whether the traffic-driven fallback is needed, and a
 * stat() is the only thing cheap enough to do that often. The content
 * says whether the last run came from cron or from a visitor, because
 * those are different answers to "is this install set up properly".
 */
final class Heartbeat
{
    public const CRON = 'cron';

    public const WEB = 'web';

    /** Set by the fallback for the duration of the run it triggers. */
    public static bool $viaWeb = false;

    public static function path(): string
    {
        return (string) config('doba.scheduler.heartbeat_path', storage_path('framework/scheduler-heartbeat.json'));
    }

    public static function beat(): void
    {
        $previous = self::read();
        $now = CarbonImmutable::now()->getTimestamp();
        $via = self::$viaWeb ? self::WEB : self::CRON;

        @file_put_contents(self::path(), (string) json_encode([
            'at' => $now,
            'via' => $via,
            // Kept separately: the health check asks "when did a REAL cron
            // last run", and a day of web runs must not erase the answer.
            'cron_at' => $via === self::CRON ? $now : ($previous['cron_at'] ?? null),
        ]), LOCK_EX);
    }

    /**
     * @return array{at?:int,via?:string,cron_at?:int|null}
     */
    public static function read(): array
    {
        $raw = @file_get_contents(self::path());
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** Seconds since anything ran the scheduler; null when never. */
    public static function age(): ?int
    {
        $at = self::read()['at'] ?? null;

        return $at === null ? null : max(0, CarbonImmutable::now()->getTimestamp() - (int) $at);
    }

    /** Seconds since a real cron ran it; null when none ever has. */
    public static function cronAge(): ?int
    {
        $at = self::read()['cron_at'] ?? null;

        return $at === null ? null : max(0, CarbonImmutable::now()->getTimestamp() - (int) $at);
    }
}
