<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 * @property string $slug
 * @property string $title
 */
class EventTranslation extends Translation
{
    protected $fillable = [
        'event_id', 'locale', 'slug', 'title', 'excerpt', 'body',
        'meta_title', 'meta_description',
    ];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
