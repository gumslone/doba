<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use App\Enums\AdjustmentType;
use App\Models\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A way of selling the same room: flexible, non-refundable saver, early
 * bird, long stay (§5).
 *
 * The plan moves the resolved nightly price and carries the cancellation
 * terms the guest agrees to. Those terms are snapshotted onto the booking
 * — a later edit here must never change what a taken booking agreed to.
 *
 * @property string $code
 * @property string $type
 * @property AdjustmentType $adjustment_type
 * @property int $adjustment_value
 * @property bool $refundable
 * @property int $cancellation_hours
 */
class RatePlan extends Model
{
    use HasTranslations;

    protected string $translationModel = RatePlanTranslation::class;

    protected string $translationForeignKey = 'rate_plan_id';

    protected $fillable = [
        'code', 'type', 'adjustment_type', 'adjustment_value',
        'min_nights', 'max_nights', 'min_days_before_arrival', 'max_days_before_arrival',
        'valid_from', 'valid_to', 'includes_breakfast',
        'refundable', 'cancellation_hours', 'is_active', 'priority',
    ];

    protected $casts = [
        'adjustment_type' => AdjustmentType::class,
        'adjustment_value' => 'integer',
        'min_nights' => 'integer',
        'max_nights' => 'integer',
        'min_days_before_arrival' => 'integer',
        'max_days_before_arrival' => 'integer',
        'valid_from' => AsDateString::class,
        'valid_to' => AsDateString::class,
        'includes_breakfast' => 'boolean',
        'refundable' => 'boolean',
        'cancellation_hours' => 'integer',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * @return BelongsToMany<RoomType, $this>
     */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class);
    }

    /**
     * @param  Builder<RatePlan>  $query
     * @return Builder<RatePlan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderByDesc('priority')->orderBy('id');
    }

    /**
     * Plans offered with a room type: those attached, plus every plan
     * attached to none — so a house-wide rate needs no configuration.
     *
     * @param  Builder<RatePlan>  $query
     * @return Builder<RatePlan>
     */
    public function scopeForRoomType(Builder $query, RoomType $roomType): Builder
    {
        return $query->where(static fn (Builder $q) => $q
            ->whereHas('roomTypes', static fn (Builder $r) => $r->whereKey($roomType->getKey()))
            ->orWhereDoesntHave('roomTypes'));
    }

    /**
     * Is this plan sellable for the given stay, booked today?
     *
     * Every bound is inclusive and a null bound means "no limit" — an
     * early-bird plan with min_days_before_arrival 30 is eligible exactly
     * 30 days out, not 31.
     */
    public function isEligible(CarbonInterface $checkIn, int $nights, ?CarbonInterface $bookedOn = null): bool
    {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $bookedOn = CarbonImmutable::instance($bookedOn ?? CarbonImmutable::today(config('doba.timezone')))->startOfDay();

        $daysAhead = (int) $bookedOn->diffInDays($checkIn, absolute: false);

        return match (true) {
            $this->min_nights !== null && $nights < $this->min_nights => false,
            $this->max_nights !== null && $nights > $this->max_nights => false,
            $this->min_days_before_arrival !== null && $daysAhead < $this->min_days_before_arrival => false,
            $this->max_days_before_arrival !== null && $daysAhead > $this->max_days_before_arrival => false,
            // The validity window is about the STAY, not the booking date:
            // a summer rate is defined by when the guest sleeps here.
            $this->valid_from !== null && $checkIn->lt($this->valid_from) => false,
            $this->valid_to !== null && $checkIn->gt($this->valid_to) => false,
            default => true,
        };
    }

    /**
     * Apply this plan's adjustment to a resolved price (§7 step 4).
     *
     * Never returns a negative amount: a −120% plan is a configuration
     * mistake, and charging a guest a negative total is worse than
     * charging them nothing.
     */
    public function adjust(int $priceMinor): int
    {
        $adjusted = match ($this->adjustment_type) {
            // Basis points, so −10% is −1000 and a 7.5% surcharge needs no
            // float anywhere in the money path.
            AdjustmentType::Percent => (int) round($priceMinor * (10000 + $this->adjustment_value) / 10000),
            AdjustmentType::Fixed => $priceMinor + $this->adjustment_value,
        };

        return max(0, $adjusted);
    }

    /**
     * The moment free cancellation ends for a stay arriving then.
     */
    public function freeCancellationDeadline(CarbonInterface $checkIn): ?CarbonImmutable
    {
        if (! $this->refundable) {
            return null;
        }

        return CarbonImmutable::instance($checkIn)
            ->startOfDay()
            ->subHours($this->cancellation_hours);
    }
}
