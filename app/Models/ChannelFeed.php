<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One subscription to an OTA calendar (§9).
 *
 * @property string $channel
 * @property string|null $import_url
 * @property bool $is_active
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $last_success_at
 * @property int|null $last_event_count
 * @property int $consecutive_error_count
 * @property string|null $last_error
 */
class ChannelFeed extends Model
{
    protected $fillable = [
        'room_type_id', 'channel', 'name', 'import_url', 'is_active',
        'last_synced_at', 'last_success_at', 'last_event_count',
        'consecutive_error_count', 'last_error',
    ];

    /**
     * Mirrored from the schema defaults so a freshly created feed reads
     * as active in memory too — a database-only default leaves the
     * in-memory model's is_active null, and a null feed is skipped by
     * every sync without saying so.
     */
    protected $attributes = [
        'is_active' => true,
        'consecutive_error_count' => 0,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'immutable_datetime',
        'last_success_at' => 'immutable_datetime',
        'last_event_count' => 'integer',
        'consecutive_error_count' => 'integer',
    ];

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return HasMany<ChannelBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(ChannelBooking::class);
    }

    /**
     * A sync that is failing or has gone quiet (§9).
     *
     * Staleness is a symptom in its own right: a dead sync and a quiet
     * week look identical from the dashboard, and only one of them will
     * eventually put two guests in one room.
     */
    public function isUnhealthy(): bool
    {
        if ($this->import_url === null || ! $this->is_active) {
            return false;
        }

        return $this->consecutive_error_count > 2
            || $this->last_success_at === null
            || $this->last_success_at->lt(now()->subHour());
    }
}
