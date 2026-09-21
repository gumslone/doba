<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The registration form a guest filled in before arriving (§12).
 *
 * @property int $booking_id
 * @property array<int,array<string,string|null>> $party
 * @property CarbonImmutable $submitted_at
 */
class BookingRegistration extends Model
{
    /** What is asked of every person in the party, in form order. */
    public const FIELDS = ['first_name', 'last_name', 'date_of_birth', 'nationality', 'street', 'postal_code', 'city', 'country', 'document_type', 'document_number'];

    protected $fillable = ['booking_id', 'party', 'submitted_at', 'ip_address'];

    protected $casts = [
        'party' => 'encrypted:array',
        'submitted_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
