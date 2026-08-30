<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A door (§5, phase 2).
 *
 * The availability engine has never heard of this model, and must never:
 * it sells categories against `allotment`. A Room exists so a sold
 * category can be pinned to a door, and so housekeeping has something to
 * mark clean.
 *
 * @property string $number
 * @property string|null $floor
 * @property string $status
 * @property string|null $notes
 */
class Room extends Model
{
    public const STATUSES = ['clean', 'dirty', 'out_of_order'];

    protected $fillable = ['room_type_id', 'number', 'floor', 'status', 'notes'];

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return HasMany<BookingRoom, $this>
     */
    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }

    /**
     * Stays that keep this door busy somewhere in [from, to).
     *
     * Half-open on purpose, matching the night model (§6): a stay that
     * checks out on the morning this one checks in does not collide.
     * CheckedOut and Cancelled and NoShow release the door regardless of
     * dates — the guest is demonstrably not in it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOccupiedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereHas('bookingRooms.booking', function ($booking) use ($from, $to): void {
            $booking->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::CheckedIn])
                ->where('check_in', '<', $to->toDateString())
                ->where('check_out', '>', $from->toDateString());
        });
    }

    public function isAssignable(): bool
    {
        return $this->status !== 'out_of_order';
    }
}
