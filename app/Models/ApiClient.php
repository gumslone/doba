<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A partner allowed to call the API (§17).
 *
 * @property string $key_id
 * @property array<int,string> $scopes
 * @property array<int,string>|null $ip_allowlist
 * @property bool $sandbox
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
class ApiClient extends Model
{
    /**
     * Granted individually, and defaulting to none.
     *
     * `bookings:write` without `bookings:cancel` is a perfectly normal
     * grant — a channel manager that can take a booking has no business
     * cancelling one on the hotel's behalf.
     */
    public const SCOPES = [
        'hotel:read',
        'availability:read',
        'rates:read',
        'bookings:read',
        'bookings:write',
        'bookings:cancel',
    ];

    protected $fillable = [
        'name', 'key_id', 'secret_hash', 'scopes', 'ip_allowlist',
        'sandbox', 'expires_at', 'revoked_at', 'last_used_at',
    ];

    protected $hidden = ['secret_hash'];

    protected $attributes = [
        'sandbox' => false,
    ];

    protected $casts = [
        'scopes' => 'array',
        'ip_allowlist' => 'array',
        'sandbox' => 'boolean',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'last_used_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<ApiIdempotencyKey, $this>
     */
    public function idempotencyKeys(): HasMany
    {
        return $this->hasMany(ApiIdempotencyKey::class);
    }

    /**
     * Mint a client, returning it with the one and only sight of its
     * secret.
     *
     * @param  array<int,string>  $scopes
     * @return array{client:self,secret:string}
     */
    public static function issue(string $name, array $scopes, bool $sandbox = false): array
    {
        // 48 characters of secret, hashed the way a password is. There is
        // no recovery path on purpose: a key the admin can read back is a
        // key that leaks with a database backup.
        $secret = Str::random(48);

        $client = static::create([
            'name' => $name,
            'key_id' => 'dk_'.Str::lower(Str::random(24)),
            'secret_hash' => Hash::make($secret),
            'scopes' => array_values(array_intersect($scopes, self::SCOPES)),
            'sandbox' => $sandbox,
        ]);

        return ['client' => $client, 'secret' => $secret];
    }

    public function verify(string $secret): bool
    {
        return Hash::check($secret, $this->secret_hash);
    }

    public function isUsable(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->gt($at));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * Empty allowlist means anywhere. A partner that can name its egress
     * addresses should, and one that cannot should not be forced to
     * invent them.
     */
    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->ip_allowlist ?? [];

        return $allowed === [] || ($ip !== null && in_array($ip, $allowed, true));
    }
}
