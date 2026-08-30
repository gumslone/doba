<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $email
 * @property string $first_name
 * @property string $last_name
 * @property int $stays_count
 * @property int $total_spent
 * @property CarbonImmutable|null $anonymised_at
 */
class Guest extends Model
{
    protected $fillable = [
        'email', 'first_name', 'last_name', 'phone', 'country', 'address',
        'city', 'postal_code', 'locale', 'marketing_consent', 'notes',
    ];

    protected $casts = [
        'marketing_consent' => 'boolean',
        'stays_count' => 'integer',
        'total_spent' => 'integer',
        'anonymised_at' => 'immutable_datetime',
    ];

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Deduplicated by lowercased email so repeat guests build a history
     * (§5); details are refreshed with whatever the latest booking knows.
     *
     * @param  array<string,mixed>  $attributes
     */
    public static function findOrCreateByEmail(string $email, array $attributes = []): self
    {
        // The lookup key must also be the stored value: leaving the caller's
        // mixed-case email in $attributes would win the merge inside
        // firstOrCreate and quietly fork the guest on their next booking.
        unset($attributes['email']);

        $guest = static::query()->firstOrCreate(
            ['email' => mb_strtolower(trim($email))],
            $attributes
        );

        if (! $guest->wasRecentlyCreated && $attributes !== []) {
            $guest->fill(array_filter($attributes, static fn ($value) => $value !== null && $value !== ''));
            $guest->save();
        }

        return $guest;
    }
}
