<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $description
 * @property int $quantity
 * @property int $tax_rate
 * @property int $line_net
 * @property int $tax_amount
 * @property int $line_gross
 */
class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'description', 'quantity', 'tax_rate',
        'unit_net', 'line_net', 'tax_amount', 'line_gross', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'tax_rate' => 'integer',
        'unit_net' => 'integer',
        'line_net' => 'integer',
        'tax_amount' => 'integer',
        'line_gross' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
