<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical-room's worth of a booking — a booking may contain several
 * room types, and the cancellation policy snapshot lives here rather than
 * on the booking because a booking may mix refundable and non-refundable
 * plans (§5, §7).
 *
 * @property int $booking_id
 * @property int $room_type_id
 * @property int $price_total
 */
class BookingRoom extends Model
{
    protected $fillable = [
        'booking_id', 'room_type_id', 'rate_plan_id', 'room_id',
        'adults', 'children', 'guest_name', 'price_total',
        'cancellation_policy_snapshot', 'cancellation_hours_snapshot', 'refundable_snapshot',
    ];

    protected $casts = [
        'adults' => 'integer',
        'children' => 'integer',
        'price_total' => 'integer',
        'cancellation_hours_snapshot' => 'integer',
        'refundable_snapshot' => 'boolean',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The door this stay was pinned to, if the desk has picked one.
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return HasMany<BookingRoomNight, $this>
     */
    public function nights(): HasMany
    {
        return $this->hasMany(BookingRoomNight::class);
    }
}
