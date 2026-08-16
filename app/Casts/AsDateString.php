<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar date stored as a plain 'Y-m-d' string (§5, §20 risk 4):
 * a night is a night, not an instant.
 *
 * Laravel's built-in date casts write the model's full datetime format into
 * the column, so a `date` column silently holds '2026-08-26 00:00:00' — and
 * then a WHERE against the 'Y-m-d' string the rest of the system uses never
 * matches, which is how an updateOrCreate turns into a unique-constraint
 * violation. This cast reads as CarbonImmutable and always writes 'Y-m-d'.
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonImmutable|string|null>
 */
class AsDateString implements CastsAttributes
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->startOfDay();
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)->toDateString()
            : CarbonImmutable::parse((string) $value)->toDateString();
    }
}
