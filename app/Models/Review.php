<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verified guest review (§5, FEATURE_REVIEWS).
 *
 * @property int $rating
 * @property string|null $title
 * @property string $body
 * @property string $locale
 * @property bool $is_published
 * @property CarbonImmutable|null $published_at
 * @property string|null $hotel_response
 */
class Review extends Model
{
    protected $fillable = [
        'booking_id', 'guest_id', 'rating', 'title', 'body', 'locale',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'immutable_datetime',
        'responded_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * The numbers the schema.org AggregateRating carries — computed from
     * every published review and nothing else. The moment this reads
     * anything but the table it summarises, the stars in a search result
     * are an assertion instead of an average.
     *
     * @return array{count:int,average:float}|null
     */
    public static function aggregate(): ?array
    {
        $count = static::query()->published()->count();

        if ($count === 0) {
            return null;
        }

        return [
            'count' => $count,
            'average' => round((float) static::query()->published()->avg('rating'), 1),
        ];
    }
}
