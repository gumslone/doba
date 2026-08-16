<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A CMS page. `code` is the internal, stable handle ("privacy", "imprint")
 * used by code and by the installer's starter content; the routable slug is
 * per-locale and lives in page_translations (§5).
 *
 * @property int $id
 * @property string $code
 * @property string $template
 * @property bool $is_published
 */
class Page extends Model
{
    use HasTranslations;

    protected string $translationModel = PageTranslation::class;

    protected string $translationForeignKey = 'page_id';

    protected $fillable = [
        'code', 'template', 'is_published', 'sort_order', 'show_in_menu', 'noindex',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'show_in_menu' => 'boolean',
        'noindex' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('sort_order');
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public static function findBySlug(string $slug, string $locale): ?static
    {
        return static::query()
            ->published()
            ->whereHas('translations', static fn (Builder $q) => $q
                ->where('locale', $locale)
                ->where('slug', $slug))
            ->with('translations')
            ->first();
    }
}
