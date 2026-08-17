<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use App\Enums\BookingStatus;
use App\Support\Hotel\HotelSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property string $reference
 * @property string $manage_token
 * @property BookingStatus $status
 * @property CarbonImmutable $check_in
 * @property CarbonImmutable $check_out
 * @property int $nights
 * @property int $subtotal
 * @property int $total
 * @property int $guest_id
 */
class Booking extends Model
{
    protected $fillable = [
        'reference', 'manage_token', 'status', 'source', 'channel_reference',
        'check_in', 'check_out', 'nights', 'adults', 'children', 'children_ages',
        'currency', 'subtotal', 'extras_total', 'discount_total', 'tax_total',
        'city_tax', 'total', 'deposit_due', 'paid_amount', 'balance_due',
        'promo_code_id', 'locale', 'guest_id', 'guest_notes', 'internal_notes',
        'cancellation_reason', 'cancelled_at', 'confirmed_at',
        'ip_address', 'user_agent',
    ];

    protected $casts = [
        'status' => BookingStatus::class,
        'check_in' => AsDateString::class,
        'check_out' => AsDateString::class,
        'nights' => 'integer',
        'adults' => 'integer',
        'children' => 'integer',
        'children_ages' => 'array',
        'subtotal' => 'integer',
        'extras_total' => 'integer',
        'discount_total' => 'integer',
        'tax_total' => 'integer',
        'city_tax' => 'integer',
        'total' => 'integer',
        'deposit_due' => 'integer',
        'paid_amount' => 'integer',
        'balance_due' => 'integer',
        'cancelled_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return HasMany<BookingRoom, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }

    /**
     * @return HasOne<PromoCodeRedemption, $this>
     */
    public function redemption(): HasOne
    {
        return $this->hasOne(PromoCodeRedemption::class);
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<BookingExtra, $this>
     */
    public function extras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    /**
     * @return HasMany<BookingHold, $this>
     */
    public function holds(): HasMany
    {
        return $this->hasMany(BookingHold::class);
    }

    /**
     * @return HasMany<BookingStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    /**
     * Human-readable, unique, e.g. ALP-2026-0412: hotel prefix, year,
     * per-year sequence. The unique index is the real guarantee; the loop
     * only spares the guest a retry on a photo-finish collision.
     */
    public static function nextReference(): string
    {
        $prefix = mb_strtoupper(mb_substr(
            preg_replace('/[^a-zA-Z]/', '', app(HotelSettings::class)->name) ?: 'DBA',
            0,
            3
        ));
        $year = now()->format('Y');

        $sequence = static::query()
            ->where('reference', 'like', "{$prefix}-{$year}-%")
            ->count() + 1;

        while (true) {
            $reference = sprintf('%s-%s-%04d', $prefix, $year, $sequence);

            if (! static::query()->where('reference', $reference)->exists()) {
                return $reference;
            }

            $sequence++;
        }
    }

    public static function newManageToken(): string
    {
        return Str::random(40);
    }
}
