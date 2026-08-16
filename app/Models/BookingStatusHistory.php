<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every transition, forever — the audit trail a disputed cancellation is
 * settled with (§5).
 *
 * @property BookingStatus|null $from_status
 * @property BookingStatus $to_status
 */
class BookingStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'booking_status_history';

    protected $fillable = ['booking_id', 'from_status', 'to_status', 'user_id', 'reason', 'created_at'];

    protected $casts = [
        'from_status' => BookingStatus::class,
        'to_status' => BookingStatus::class,
        'created_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
