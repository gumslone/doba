<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $gift_voucher_id
 * @property int $booking_id
 * @property int $payment_id
 * @property int $amount
 * @property CarbonImmutable|null $restored_at
 */
class GiftVoucherRedemption extends Model
{
    protected $fillable = ['gift_voucher_id', 'booking_id', 'payment_id', 'amount', 'restored_at'];

    protected $casts = [
        'amount' => 'integer',
        'restored_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<GiftVoucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(GiftVoucher::class, 'gift_voucher_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
