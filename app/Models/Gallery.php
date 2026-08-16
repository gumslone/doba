<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The 'hotel' gallery is the house photo set — the home hero, the strip
 * under the rooms, the og:image fallback. Others can exist (spa,
 * restaurant) and attach wherever a page needs them.
 *
 * @property string $code
 */
class Gallery extends Model implements HasMedia
{
    use HasTranslations;

    public const HOTEL = 'hotel';

    protected string $translationModel = GalleryTranslation::class;

    protected string $translationForeignKey = 'gallery_id';

    protected $fillable = ['code', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    /**
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('sort_order');
    }

    public function coverImage(): ?Media
    {
        return $this->media->firstWhere('is_cover', true) ?? $this->media->first();
    }

    public static function hotel(): self
    {
        return static::query()->with('media')->firstOrCreate(['code' => self::HOTEL]);
    }
}
