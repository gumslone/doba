<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppliesPer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra as taken on one booking, with its price frozen — extras change
 * price like rooms do, and a taken booking's total may not move (§7).
 *
 * @property int $quantity
 * @property int $unit_price
 * @property int $total
 * @property AppliesPer $applies_per
 */
class BookingExtra extends Model
{
    protected $fillable = [
        'booking_id', 'extra_id', 'quantity',
        'unit_price', 'total', 'applies_per', 'tax_rate',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total' => 'integer',
        'applies_per' => AppliesPer::class,
        'tax_rate' => 'integer',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Extra, $this>
     */
    public function extra(): BelongsTo
    {
        return $this->belongsTo(Extra::class);
    }
}
