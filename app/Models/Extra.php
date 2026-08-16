<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppliesPer;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable add-on: breakfast, airport transfer, spa entry, parking, a
 * cot, late checkout (§5).
 *
 * Money is integer minor units; `tax_rate` is basis points so 19% is 1900
 * and no fractional-percent VAT rate (Germany has had 16.5%) needs a float.
 *
 * @property string $code
 * @property int $price
 * @property AppliesPer $applies_per
 * @property int $tax_rate
 * @property bool $is_included
 */
class Extra extends Model
{
    use HasTranslations;

    protected string $translationModel = ExtraTranslation::class;

    protected string $translationForeignKey = 'extra_id';

    protected $fillable = [
        'code', 'price', 'applies_per', 'tax_rate', 'icon',
        'max_quantity', 'is_active', 'is_included', 'sort_order',
    ];

    protected $casts = [
        'price' => 'integer',
        'applies_per' => AppliesPer::class,
        'tax_rate' => 'integer',
        'max_quantity' => 'integer',
        'is_active' => 'boolean',
        'is_included' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsToMany<RoomType, $this>
     */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class);
    }

    /**
     * @return HasMany<BookingExtra, $this>
     */
    public function bookingExtras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    /**
     * @param  Builder<Extra>  $query
     * @return Builder<Extra>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Extras available with a given room type: those explicitly attached,
     * plus every extra attached to no room type at all — a hotel selling
     * breakfast house-wide should not have to attach it to each room.
     *
     * @param  Builder<Extra>  $query
     * @return Builder<Extra>
     */
    public function scopeForRoomType(Builder $query, RoomType $roomType): Builder
    {
        return $query->where(static fn (Builder $q) => $q
            ->whereHas('roomTypes', static fn (Builder $r) => $r->whereKey($roomType->getKey()))
            ->orWhereDoesntHave('roomTypes'));
    }

    /**
     * What this extra costs for a stay, before tax.
     */
    public function totalFor(int $nights, int $guests, int $quantity = 1): int
    {
        return $this->price * $this->applies_per->multiplier($nights, $guests) * max(1, $quantity);
    }
}
