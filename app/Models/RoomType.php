<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * The sellable unit — a room *category*, not a physical room.
 *
 * Money is integer minor units (§5): default_rate 12500 is €125.00.
 *
 * @property int $id
 * @property string $code
 * @property int $base_occupancy
 * @property int $max_occupancy
 * @property int|null $default_rate
 * @property string|null $ical_token
 */
class RoomType extends Model implements HasMedia
{
    use HasTranslations;

    protected string $translationModel = RoomTypeTranslation::class;

    protected string $translationForeignKey = 'room_type_id';

    protected $fillable = [
        'code', 'base_occupancy', 'max_occupancy', 'max_adults', 'max_children',
        'extra_adult_price', 'extra_child_price', 'size_sqm', 'bed_setup',
        'default_rate', 'total_units', 'sort_order', 'is_active',
    ];

    /**
     * Deliberately absent from $fillable: the calendar token is the only
     * credential on the export URL, so it is generated here and can never
     * arrive from a request.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $roomType): void {
            $roomType->ical_token ??= Str::random(40);
        });
    }

    /**
     * The subscribable calendar URL handed to Booking.com or Airbnb.
     */
    public function icalUrl(): ?string
    {
        return $this->ical_token === null
            ? null
            : url("/ical/{$this->id}/{$this->ical_token}.ics");
    }

    protected $casts = [
        'base_occupancy' => 'integer',
        'max_occupancy' => 'integer',
        'max_adults' => 'integer',
        'max_children' => 'integer',
        'extra_adult_price' => 'integer',
        'extra_child_price' => 'integer',
        'size_sqm' => 'integer',
        'default_rate' => 'integer',
        'total_units' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('sort_order');
    }

    /**
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class)->orderBy('sort_order');
    }

    /**
     * Amenity names in the current locale — the shape both the Blade list
     * and the JSON-LD amenityFeature entries consume.
     *
     * @return array<int,string>
     */
    public function amenityNames(): array
    {
        return $this->amenities
            ->map(static fn (Amenity $amenity): ?string => $amenity->t('name'))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * What the room includes, grouped for the guest: bed, bathroom,
     * comfort, view. Categories keep their declared order and empty ones
     * disappear.
     *
     * @return array<string,array<int,Amenity>>
     */
    public function inclusionsByCategory(): array
    {
        $grouped = $this->amenities->groupBy('category');
        $ordered = [];

        foreach (Amenity::CATEGORIES as $category) {
            if ($grouped->has($category)) {
                $ordered[$category] = $grouped->get($category)->all();
            }
        }

        // Anything using a category the constant does not know still shows,
        // rather than silently vanishing from the room page.
        foreach ($grouped as $category => $amenities) {
            if (! isset($ordered[$category])) {
                $ordered[$category] = $amenities->all();
            }
        }

        return $ordered;
    }

    /**
     * @return Collection<int,Extra>
     */
    public function availableExtras(): Collection
    {
        return Extra::query()
            ->active()
            ->forRoomType($this)
            ->with('translations')
            ->get();
    }

    /**
     * @param  Builder<RoomType>  $query
     * @return Builder<RoomType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<RoomType>  $query
     * @return Builder<RoomType>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Resolve by the slug of a given locale.
     *
     * Scoped to the locale, not to any locale, so /en/rooms/doppelzimmer is a
     * 404 rather than a second URL for a page that already exists at
     * /de/zimmer/doppelzimmer.
     */
    public static function findBySlug(string $slug, string $locale): ?static
    {
        return static::query()
            ->active()
            ->whereHas('translations', static fn (Builder $q) => $q
                ->where('locale', $locale)
                ->where('slug', $slug))
            ->with(['translations', 'media', 'amenities.translations'])
            ->first();
    }

    public function coverImage(): ?Media
    {
        return $this->media->firstWhere('is_cover', true) ?? $this->media->first();
    }
}
