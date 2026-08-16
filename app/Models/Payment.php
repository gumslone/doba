<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One money movement (§5). Refunds are their own rows with type 'refund',
 * never a negative mutation of the original — the original row plus its
 * refunds must always reconstruct what actually happened, because that
 * reconstruction is what settles a chargeback.
 *
 * @property int $booking_id
 * @property string $gateway
 * @property string|null $gateway_payment_id
 * @property string $type
 * @property PaymentStatus $status
 * @property int $amount
 * @property string $currency
 */
class Payment extends Model
{
    protected $fillable = [
        'booking_id', 'gateway', 'gateway_payment_id', 'gateway_customer_id',
        'type', 'status', 'amount', 'currency', 'fee', 'payload',
        'paid_at', 'refunded_at', 'idempotency_key',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'integer',
        'fee' => 'integer',
        'payload' => 'array',
        'paid_at' => 'immutable_datetime',
        'refunded_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
