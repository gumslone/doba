<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property string $number
 * @property int $net_total
 * @property int $tax_total
 * @property int $gross_total
 * @property array<string,mixed>|null $billed_to
 */
class Invoice extends Model
{
    protected $fillable = [
        'booking_id', 'number', 'year', 'sequence', 'issued_at', 'pdf_path',
        'currency', 'net_total', 'tax_total', 'gross_total', 'billed_to',
    ];

    protected $casts = [
        'year' => 'integer',
        'sequence' => 'integer',
        'issued_at' => 'immutable_datetime',
        'net_total' => 'integer',
        'tax_total' => 'integer',
        'gross_total' => 'integer',
        'billed_to' => 'array',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The VAT summary a DE/PL/UA invoice must print: one row per rate,
     * with its own net, tax and gross (§5).
     *
     * @return Collection<int,array{rate:int,net:int,tax:int,gross:int}>
     */
    public function taxBreakdown(): Collection
    {
        return $this->lines
            ->groupBy('tax_rate')
            ->map(static fn (Collection $lines, int|string $rate): array => [
                'rate' => (int) $rate,
                'net' => (int) $lines->sum('line_net'),
                'tax' => (int) $lines->sum('tax_amount'),
                'gross' => (int) $lines->sum('line_gross'),
            ])
            ->sortBy('rate')
            ->values();
    }
}
