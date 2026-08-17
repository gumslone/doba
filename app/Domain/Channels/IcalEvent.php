<?php

declare(strict_types=1);

namespace App\Domain\Channels;

use Carbon\CarbonImmutable;

/**
 * One VEVENT, reduced to what an availability block is: an id and a
 * half-open range of nights. `end` is exclusive, like DTEND and like
 * check_out.
 */
final readonly class IcalEvent
{
    public function __construct(
        public string $uid,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?string $summary = null,
    ) {}

    public function nights(): int
    {
        return (int) $this->start->diffInDays($this->end);
    }
}
