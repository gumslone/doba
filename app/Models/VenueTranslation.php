<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 * @property string $slug
 * @property string $name
 */
class VenueTranslation extends Translation
{
    protected $fillable = [
        'venue_id', 'locale', 'slug', 'name', 'tagline',
        'description', 'meta_title', 'meta_description',
    ];

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
