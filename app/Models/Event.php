<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A dated happening at the hotel — wine tasting, live music, a seasonal
 * dinner. Routable per locale like pages, listed on the front page while
 * upcoming, and published as schema.org Event so it can surface in event
 * search results — one of the few SERP features an independent hotel can
 * win that an OTA listing cannot.
 *
 * @property int $id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $location
 * @property bool $is_published
 */
class Event extends Model
{
    use HasTranslations;

    protected string $translationModel = EventTranslation::class;

    protected string $translationForeignKey = 'event_id';

    protected $fillable = ['starts_at', 'ends_at', 'location', 'is_published'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_published' => 'boolean',
    ];

    /**
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('sort_order');
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        // An event still "happening" (multi-day, or started an hour ago)
        // stays listed until it actually ends.
        return $query
            ->where(static fn (Builder $q) => $q
                ->where('starts_at', '>=', now()->startOfDay())
                ->orWhere('ends_at', '>=', now()))
            ->orderBy('starts_at');
    }

    public static function findBySlug(string $slug, string $locale): ?self
    {
        return static::query()
            ->published()
            ->whereHas('translations', static fn (Builder $q) => $q
                ->where('locale', $locale)
                ->where('slug', $slug))
            ->with(['translations', 'media'])
            ->first();
    }
}
