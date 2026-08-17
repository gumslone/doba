<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a promo code (§5).
 *
 * Kept even after the booking is cancelled — `released_at` is stamped
 * instead — so "how did the newsletter code do?" stays answerable and a
 * code that ran out can be explained afterwards.
 *
 * @property int $amount
 * @property CarbonImmutable $redeemed_at
 * @property CarbonImmutable|null $released_at
 */
class PromoCodeRedemption extends Model
{
    protected $fillable = [
        'promo_code_id', 'booking_id', 'guest_id', 'amount', 'redeemed_at', 'released_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'redeemed_at' => 'immutable_datetime',
        'released_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
