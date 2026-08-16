<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-night price snapshot — rates change; a confirmed booking's price
 * never does (§5).
 *
 * @property CarbonImmutable $date
 * @property int $price
 */
class BookingRoomNight extends Model
{
    protected $fillable = ['booking_room_id', 'date', 'price'];

    protected $casts = [
        'date' => AsDateString::class,
        'price' => 'integer',
    ];

    /**
     * @return BelongsTo<BookingRoom, $this>
     */
    public function bookingRoom(): BelongsTo
    {
        return $this->belongsTo(BookingRoom::class);
    }
}
