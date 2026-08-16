<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * One polymorphic media table for the whole system (§5) — room types,
 * pages, galleries. Two image tables means writing image handling twice.
 *
 * `alt` is JSON per locale: alt text is content, and a German page with
 * English alt attributes is both an accessibility and an SEO defect.
 *
 * @property string $path
 * @property string $disk
 * @property array<string,string>|null $alt
 * @property int|null $width
 * @property int|null $height
 * @property bool $is_cover
 */
class Media extends Model
{
    protected $fillable = [
        'mediable_type', 'mediable_id', 'path', 'disk', 'alt',
        'width', 'height', 'sort_order', 'is_cover',
    ];

    protected $casts = [
        'alt' => 'array',
        'width' => 'integer',
        'height' => 'integer',
        'sort_order' => 'integer',
        'is_cover' => 'boolean',
    ];

    protected $attributes = [
        'disk' => 'public',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(): string
    {
        return str_starts_with($this->path, 'http')
            ? $this->path
            : Storage::disk($this->disk)->url($this->path);
    }

    public function altFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->alt[$locale]
            ?? $this->alt[config('app.fallback_locale')]
            ?? '';
    }
}
