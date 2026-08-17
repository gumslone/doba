<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One OTA-held stay, imported from an iCal feed (§9).
 *
 * The row is the ledger entry explaining a slice of `availability.booked`:
 * the counter says how many units are gone tonight, this says which of
 * them Booking.com is holding and under what external id.
 *
 * @property string $external_uid
 * @property CarbonImmutable $check_in
 * @property CarbonImmutable $check_out
 * @property int $units
 * @property CarbonImmutable|null $missing_since
 * @property int $missing_syncs
 * @property bool $needs_review
 * @property CarbonImmutable|null $released_at
 */
class ChannelBooking extends Model
{
    protected $fillable = [
        'channel_feed_id', 'room_type_id', 'external_uid', 'summary',
        'check_in', 'check_out', 'units',
        'missing_since', 'missing_syncs', 'needs_review', 'released_at',
    ];

    protected $attributes = [
        'units' => 1,
        'missing_syncs' => 0,
        'needs_review' => false,
    ];

    protected $casts = [
        'check_in' => AsDateString::class,
        'check_out' => AsDateString::class,
        'units' => 'integer',
        'missing_since' => 'immutable_datetime',
        'missing_syncs' => 'integer',
        'needs_review' => 'boolean',
        'released_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<ChannelFeed, $this>
     */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(ChannelFeed::class, 'channel_feed_id');
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @param  Builder<ChannelBooking>  $query
     * @return Builder<ChannelBooking>
     */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }
}
