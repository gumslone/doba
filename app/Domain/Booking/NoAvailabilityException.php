<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use RuntimeException;

/**
 * The friendly "just taken" outcome (§6): the loser of a race on the last
 * room gets this, never a driver error — a distinction the concurrency
 * tests treat as load-bearing.
 */
class NoAvailabilityException extends RuntimeException
{
    public function __construct(public readonly string $date)
    {
        parent::__construct("No availability on {$date}.");
    }
}
