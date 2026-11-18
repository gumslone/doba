<?php

declare(strict_types=1);

namespace App\Support\Hotel;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The hotel's clock (§5).
 *
 * Doba runs on ONE clock: the hotel's. Almost everything it stores is a
 * calendar date or a time somebody at the desk will read, so the whole
 * process — guest pages, admin, cron, API, webhooks — keeps the hotel's
 * timezone, set once in config/app.php.
 *
 * It did not use to. `app.timezone` said UTC while the guest site alone
 * switched PHP to the hotel's zone, so the same column held hotel
 * wall-clock time when a guest's request wrote it and UTC when cron, an
 * admin or a webhook did. A 20-minute hold lasted 80 minutes in Berlin
 * and expired on arrival in New York; the front desk's clock ran an hour
 * or two behind the wall.
 *
 * This class is where code asks for the hotel's time by name rather than
 * trusting now(), so the intent survives the next person who touches
 * config/app.php.
 */
final class Clock
{
    public static function timezone(): string
    {
        return (string) config('doba.timezone', 'UTC');
    }

    /**
     * Change the hotel's timezone for the rest of this request.
     *
     * For the two screens that let a hotelier pick one, and for tests.
     * Everything else gets it from the environment at boot.
     */
    public static function use(string $timezone): void
    {
        config(['doba.timezone' => $timezone, 'app.timezone' => $timezone]);

        date_default_timezone_set($timezone);
    }

    /** This instant, on the hotel's wall clock. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /** The hotel's calendar day, at its own midnight. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today(self::timezone());
    }

    /**
     * An instant, as the hotel's wall clock shows it.
     */
    public static function local(?DateTimeInterface $instant): ?CarbonImmutable
    {
        return $instant === null
            ? null
            : CarbonImmutable::instance($instant)->setTimezone(self::timezone());
    }

    /**
     * Midnight at the hotel on a calendar date.
     *
     * For deadlines: "free cancellation until 48 hours before arrival"
     * counts back from the hotel's midnight on the arrival day.
     */
    public static function startOfDay(DateTimeInterface|string $date): CarbonImmutable
    {
        $day = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        return CarbonImmutable::parse($day.' 00:00:00', self::timezone());
    }
}
