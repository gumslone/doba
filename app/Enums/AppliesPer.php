<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an extra's unit price multiplies out over a stay (§7 step 6).
 *
 * Stored as a plain string — the portable subset has no ENUM (§5).
 */
enum AppliesPer: string
{
    case Stay = 'stay';
    case Night = 'night';
    case Person = 'person';
    case PersonNight = 'person_night';

    /**
     * Multiplier for a stay of $nights nights and $guests guests.
     */
    public function multiplier(int $nights, int $guests): int
    {
        return match ($this) {
            self::Stay => 1,
            self::Night => max(1, $nights),
            self::Person => max(1, $guests),
            self::PersonNight => max(1, $nights) * max(1, $guests),
        };
    }

    /**
     * The lang key describing the unit, for "€12 per person per night".
     */
    public function label(): string
    {
        return 'extras.per_'.$this->value;
    }
}
