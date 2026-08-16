<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 * @property string $slug
 * @property string $title
 * @property string|null $body
 */
class PageTranslation extends Translation
{
    protected $fillable = [
        'page_id', 'locale', 'slug', 'title', 'body',
        'meta_title', 'meta_description', 'og_image',
    ];

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
