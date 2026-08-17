<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A restaurant, bar, café or lounge.
 *
 * One table with a type rather than three that drift apart: a hotel
 * almost never has exactly one, and they differ only in type, hours and
 * menu.
 *
 * @property string $code
 * @property string $type
 * @property array<string,array<int,array<int,string>>>|null $opening_hours
 * @property bool $is_active
 */
class Venue extends Model implements HasMedia
{
    use HasTranslations;

    /** Monday first, as every European opening-hours sign is written. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const TYPES = ['restaurant', 'bar', 'cafe', 'lounge'];

    protected string $translationModel = VenueTranslation::class;

    protected string $translationForeignKey = 'venue_id';

    protected $fillable = [
        'code', 'type', 'phone', 'price_range', 'seats',
        'opening_hours', 'reservations', 'is_active', 'sort_order',
    ];

    protected $attributes = [
        'type' => 'restaurant',
        'is_active' => true,
        'reservations' => false,
    ];

    protected $casts = [
        'seats' => 'integer',
        'opening_hours' => 'array',
        'reservations' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return HasMany<MenuSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(MenuSection::class)->orderBy('sort_order')->orderBy('id');
    }

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

    /**
     * @param  Builder<Venue>  $query
     * @return Builder<Venue>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The slug in a locale, or null when this venue is not translated
     * there — slugs never fall back (§10).
     */
    public function slug(?string $locale = null): ?string
    {
        return $this->t('slug', $locale, fallback: false);
    }

    public static function findBySlug(string $slug, string $locale): ?self
    {
        return static::query()
            ->active()
            ->whereHas('translations', static fn (Builder $q) => $q
                ->where('locale', $locale)
                ->where('slug', $slug))
            ->with(['translations', 'media', 'sections.translations', 'sections.dishes.translations'])
            ->first();
    }

    /**
     * Today's service periods, or an empty array on a closing day.
     *
     * @return array<int,array<int,string>>
     */
    public function hoursOn(?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();

        return $this->opening_hours[self::DAYS[$date->dayOfWeekIso - 1]] ?? [];
    }

    /**
     * Is the kitchen serving right now?
     *
     * Compared as HH:MM strings rather than parsed times: the values come
     * from a form as "18:00", the comparison is within one day, and
     * string order and clock order agree for zero-padded 24-hour times.
     */
    public function isOpenAt(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now(config('doba.timezone'));
        $now = $at->format('H:i');

        foreach ($this->hoursOn($at) as $period) {
            [$from, $to] = [$period[0] ?? null, $period[1] ?? null];

            if ($from === null || $to === null) {
                continue;
            }

            // A bar that closes at 01:00 is open PAST midnight, so a
            // period whose end is before its start wraps into tomorrow —
            // treating it as an empty range would close the bar all night.
            $open = $to > $from
                ? ($now >= $from && $now < $to)
                : ($now >= $from || $now < $to);

            if ($open) {
                return true;
            }
        }

        return false;
    }
}
