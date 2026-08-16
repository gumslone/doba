<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bulk nightly price: season × room type × weekday mask (§5).
 *
 * @property int $season_id
 * @property int $room_type_id
 * @property int $weekday_mask
 * @property int $price
 */
class SeasonRate extends Model
{
    /**
     * Weekday bits, Monday first — matching ISO 8601 weekday numbering, so
     * bit(n) = 1 << (isoWeekday − 1). 127 covers the whole week.
     */
    public const MONDAY = 1 << 0;

    public const TUESDAY = 1 << 1;

    public const WEDNESDAY = 1 << 2;

    public const THURSDAY = 1 << 3;

    public const FRIDAY = 1 << 4;

    public const SATURDAY = 1 << 5;

    public const SUNDAY = 1 << 6;

    public const ALL_WEEK = 127;

    protected $fillable = ['season_id', 'room_type_id', 'weekday_mask', 'price'];

    protected $casts = [
        'weekday_mask' => 'integer',
        'price' => 'integer',
    ];

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function matchesWeekday(CarbonInterface $date): bool
    {
        return (bool) ($this->weekday_mask & (1 << ($date->isoWeekday() - 1)));
    }
}
