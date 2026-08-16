<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The anti-overbooking mechanism (§5): each row is `units` of one room
 * type on one night, counted in availability.held until released.
 *
 * @property int $booking_id
 * @property int $room_type_id
 * @property CarbonImmutable $date
 * @property int $units
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $released_at
 */
class BookingHold extends Model
{
    protected $fillable = [
        'booking_id', 'session_id', 'room_type_id', 'date', 'units',
        'expires_at', 'released_at',
    ];

    protected $casts = [
        'date' => AsDateString::class,
        'units' => 'integer',
        'expires_at' => 'immutable_datetime',
        'released_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Still counted in availability.held — expiry alone changes nothing
     * until the release command or a conversion actually decrements.
     *
     * @param  Builder<BookingHold>  $query
     * @return Builder<BookingHold>
     */
    public function scopeUnreleased(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }
}
