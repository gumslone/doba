<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One room type × one night (§5). `booked` and `held` are caches of the
 * ground truth in the (future) booking tables — never the source of truth
 * themselves; the nightly reconcile recomputes them and alerts on drift.
 *
 * @property int $room_type_id
 * @property CarbonImmutable $date
 * @property int $allotment
 * @property int $booked
 * @property int $held
 * @property int|null $price
 * @property int $min_stay
 * @property int|null $max_stay
 * @property int|null $min_stay_through
 * @property bool $closed
 * @property bool $closed_to_arrival
 * @property bool $closed_to_departure
 */
class Availability extends Model
{
    protected $table = 'availability';

    protected $fillable = [
        'room_type_id', 'date', 'allotment', 'booked', 'held', 'price',
        'min_stay', 'max_stay', 'min_stay_through',
        'closed', 'closed_to_arrival', 'closed_to_departure',
    ];

    protected $casts = [
        'date' => AsDateString::class,
        'allotment' => 'integer',
        'booked' => 'integer',
        'held' => 'integer',
        'price' => 'integer',
        'min_stay' => 'integer',
        'max_stay' => 'integer',
        'min_stay_through' => 'integer',
        'closed' => 'boolean',
        'closed_to_arrival' => 'boolean',
        'closed_to_departure' => 'boolean',
    ];

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Units a new booking could still consume tonight.
     */
    public function unitsLeft(): int
    {
        return max(0, $this->allotment - $this->booked - $this->held);
    }
}
