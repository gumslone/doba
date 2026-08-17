<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use App\Enums\DiscountType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A promo code (§5, pricing step 7).
 *
 * Two rules keep this honest:
 *
 *  1. **A code discounts the stay, never the extras.** Breakfast and the
 *     transfer are bought at their listed price; "20% off" that quietly
 *     takes 20% off the airport transfer is not what either side meant.
 *  2. **The discount can never exceed the subtotal.** A €200 fixed code on
 *     a €150 stay is a €150 discount, not a €50 refund the hotel owes.
 *
 * @property string $code
 * @property DiscountType $discount_type
 * @property int $value
 * @property int|null $min_nights
 * @property int|null $min_total
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable|null $stay_from
 * @property CarbonImmutable|null $stay_to
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property int|null $per_guest_limit
 * @property array<int,int>|null $room_type_ids
 * @property bool $is_active
 */
class PromoCode extends Model
{
    protected $fillable = [
        'code', 'discount_type', 'value', 'min_nights', 'min_total',
        'valid_from', 'valid_to', 'stay_from', 'stay_to',
        'usage_limit', 'per_guest_limit', 'room_type_ids', 'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
        'usage_count' => 0,
    ];

    protected $casts = [
        'discount_type' => DiscountType::class,
        'value' => 'integer',
        'min_nights' => 'integer',
        'min_total' => 'integer',
        'valid_from' => AsDateString::class,
        'valid_to' => AsDateString::class,
        'stay_from' => AsDateString::class,
        'stay_to' => AsDateString::class,
        'usage_limit' => 'integer',
        'usage_count' => 'integer',
        'per_guest_limit' => 'integer',
        'room_type_ids' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<PromoCodeRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /**
     * @param  Builder<PromoCode>  $query
     * @return Builder<PromoCode>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', mb_strtoupper(trim($code)))->first();
    }

    /**
     * The discount this code takes off a stay, in minor units.
     *
     * Free nights discount the CHEAPEST nights first — the guest's read of
     * "your third night is free" is the one that costs the hotel least to
     * honour and the one every OTA uses, so matching it avoids an argument
     * at checkout.
     *
     * @param  array<array-key,int>  $nightPrices  per-night prices for one unit,
     *                                             as date => price or a plain list
     */
    public function discountFor(array $nightPrices, int $units = 1): int
    {
        $subtotal = array_sum($nightPrices) * $units;

        if ($subtotal <= 0) {
            return 0;
        }

        $discount = match ($this->discount_type) {
            // Basis points, integer division: a 12.5% code on €149.99 is
            // never a float, so it can never round to a phantom cent.
            DiscountType::Percent => intdiv($subtotal * $this->value, 10000),
            DiscountType::Fixed => $this->value,
            DiscountType::FreeNights => $this->freeNightsDiscount($nightPrices, $units),
        };

        // Never more than the stay itself: a code cannot make a booking
        // owe the guest money.
        return max(0, min($discount, $subtotal));
    }

    /**
     * @param  array<array-key,int>  $nightPrices
     */
    protected function freeNightsDiscount(array $nightPrices, int $units): int
    {
        $prices = array_values($nightPrices);
        sort($prices);

        return array_sum(array_slice($prices, 0, max(0, $this->value))) * $units;
    }

    /**
     * Why this code cannot be used for this stay, or null if it can.
     *
     * Returns a reason rather than a bool so the guest is told what is
     * wrong — "this code needs a 3-night stay" sends them back to change
     * dates; "invalid code" sends them to a competitor.
     *
     * @param  array<int,int>  $roomTypeIds
     */
    public function rejectionReason(
        CarbonInterface $checkIn,
        int $nights,
        int $subtotal,
        array $roomTypeIds,
        ?Guest $guest = null,
        ?CarbonInterface $at = null,
    ): ?string {
        $at = CarbonImmutable::instance($at ?? now())->startOfDay();
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();

        if (! $this->is_active) {
            return 'promo.error_invalid';
        }

        if ($this->valid_from !== null && $at->lt($this->valid_from)) {
            return 'promo.error_not_yet_valid';
        }

        if ($this->valid_to !== null && $at->gt($this->valid_to)) {
            return 'promo.error_expired';
        }

        if ($this->stay_from !== null && $checkIn->lt($this->stay_from)) {
            return 'promo.error_stay_window';
        }

        if ($this->stay_to !== null && $checkIn->gt($this->stay_to)) {
            return 'promo.error_stay_window';
        }

        if ($this->min_nights !== null && $nights < $this->min_nights) {
            return 'promo.error_min_nights';
        }

        if ($this->min_total !== null && $subtotal < $this->min_total) {
            return 'promo.error_min_total';
        }

        if ($this->room_type_ids !== null && array_diff($roomTypeIds, $this->room_type_ids) !== []) {
            return 'promo.error_room_type';
        }

        // Counted from live redemptions, not the cached counter: a booking
        // that was cancelled gave its use back, and a campaign that looks
        // exhausted because of abandoned checkouts is a code that stops
        // working for no reason the hotelier can see.
        if ($this->usage_limit !== null && $this->activeRedemptions() >= $this->usage_limit) {
            return 'promo.error_used_up';
        }

        if ($this->per_guest_limit !== null && $guest !== null
            && $this->activeRedemptions($guest) >= $this->per_guest_limit) {
            return 'promo.error_guest_limit';
        }

        return null;
    }

    public function activeRedemptions(?Guest $guest = null): int
    {
        return $this->redemptions()
            ->whereNull('released_at')
            ->when($guest !== null, fn (Builder $query) => $query->where('guest_id', $guest->id))
            ->count();
    }
}
