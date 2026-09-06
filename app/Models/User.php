<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

/**
 * @property string|null $totp_secret
 * @property \Carbon\CarbonImmutable|null $totp_confirmed_at
 * @property array<int,string>|null $totp_recovery_codes
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Second-factor state (§14). Encrypted at rest; never serialised.
     */
    public function hasTwoFactor(): bool
    {
        return $this->totp_confirmed_at !== null && $this->totp_secret !== null;
    }

    /**
     * Spend a recovery code. Each works exactly once: a code that could
     * be replayed is a second password, not a recovery.
     */
    public function useRecoveryCode(string $code): bool
    {
        $codes = $this->totp_recovery_codes ?? [];
        $code = strtoupper(trim($code));

        foreach ($codes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$index]);
                $this->forceFill(['totp_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
        'totp_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_recovery_codes' => 'encrypted:array',
            'totp_confirmed_at' => 'immutable_datetime',
        ];
    }
}
