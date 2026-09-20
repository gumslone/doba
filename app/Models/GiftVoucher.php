<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received in advance (§8).
 *
 * @property int $id
 * @property string $code
 * @property int $initial_amount
 * @property int $balance
 * @property string $currency
 * @property string $status
 * @property string $sold_via
 * @property string $buyer_name
 * @property string|null $buyer_email
 * @property string|null $recipient_name
 * @property string|null $message
 * @property string $locale
 * @property string|null $internal_note
 * @property CarbonImmutable|null $expires_on
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $voided_at
 * @property CarbonImmutable|null $created_at
 */
class GiftVoucher extends Model
{
    /** Ordered on the website, not yet paid for: worth nothing. */
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    /** Spent to the last cent. */
    public const REDEEMED = 'redeemed';

    public const VOID = 'void';

    /** No 0/O, 1/I/L: a code is read off paper and typed on a phone. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'code', 'initial_amount', 'balance', 'currency', 'status', 'sold_via',
        'buyer_name', 'buyer_email', 'recipient_name', 'message', 'locale',
        'internal_note', 'expires_on', 'activated_at', 'voided_at',
    ];

    protected $casts = [
        'initial_amount' => 'integer',
        'balance' => 'integer',
        'expires_on' => AsDateString::class,
        'activated_at' => 'immutable_datetime',
        'voided_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<GiftVoucherRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(GiftVoucherRedemption::class)->latest('id');
    }

    /**
     * GV-XXXX-XXXX from an alphabet without look-alikes: 31^8 ≈ 850
     * billion, and redemption is rate-limited on top.
     */
    public static function newCode(): string
    {
        do {
            $raw = '';

            for ($i = 0; $i < 8; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $code = 'GV-'.substr($raw, 0, 4).'-'.substr($raw, 4);
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    /** What a guest typed, as the code is stored. */
    public static function normalise(string $typed): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));

        if (str_starts_with($clean, 'GV') && strlen($clean) === 10) {
            return 'GV-'.substr($clean, 2, 4).'-'.substr($clean, 6);
        }

        return strtoupper(trim($typed));
    }

    public function isExpired(?CarbonImmutable $on = null): bool
    {
        $on ??= CarbonImmutable::today(config('doba.timezone'));

        return $this->expires_on !== null && $on->gt($this->expires_on);
    }

    public function isSpendable(): bool
    {
        return $this->status === self::ACTIVE && $this->balance > 0 && ! $this->isExpired();
    }
}
