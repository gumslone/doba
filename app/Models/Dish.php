<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Allergen;
use App\Enums\Diet;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One line on the card.
 *
 * @property int|null $price
 * @property string|null $unit
 * @property array<int,string>|null $allergens
 * @property array<int,string>|null $diets
 * @property bool $is_available
 * @property bool $is_signature
 */
class Dish extends Model
{
    use HasTranslations;

    protected string $translationModel = DishTranslation::class;

    protected string $translationForeignKey = 'dish_id';

    protected $fillable = [
        'menu_section_id', 'price', 'unit', 'allergens', 'diets',
        'is_available', 'is_signature', 'sort_order',
    ];

    protected $attributes = [
        'is_available' => true,
        'is_signature' => false,
    ];

    protected $casts = [
        'price' => 'integer',
        'allergens' => 'array',
        'diets' => 'array',
        'is_available' => 'boolean',
        'is_signature' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo<MenuSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class, 'menu_section_id');
    }

    /**
     * @param  Builder<Dish>  $query
     * @return Builder<Dish>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /**
     * The declarable allergens on this dish, as enum cases.
     *
     * Unknown strings are dropped rather than rendered: a value that is
     * not one of the fourteen cannot be translated or explained, and a
     * raw "nuts?" printed beside a price helps nobody.
     *
     * @return Collection<int,Allergen>
     */
    public function allergenCases(): Collection
    {
        return collect($this->allergens ?? [])
            ->map(static fn (string $value): ?Allergen => Allergen::tryFrom($value))
            ->filter()
            // Ascending, as a printed card lists them: "contains 1, 3, 7"
            // is read as a set, and an arbitrary order looks like a typo.
            ->sortBy(static fn (Allergen $allergen): int => $allergen->number())
            ->values();
    }

    /**
     * @return Collection<int,Diet>
     */
    public function dietCases(): Collection
    {
        return collect($this->diets ?? [])
            ->map(static fn (string $value): ?Diet => Diet::tryFrom($value))
            ->filter()
            ->values();
    }

    /**
     * Does this dish suit a guest avoiding these allergens?
     *
     * A dish with NO allergen data is not assumed safe — an empty list may
     * mean "contains none" or "nobody filled this in", and only one of
     * those is safe to tell a guest with an allergy. The public filter
     * therefore hides unknowns rather than presenting them as clear.
     */
    public function isFreeOf(Allergen ...$allergens): bool
    {
        if ($this->allergens === null) {
            return false;
        }

        foreach ($allergens as $allergen) {
            if (in_array($allergen->value, $this->allergens, true)) {
                return false;
            }
        }

        return true;
    }
}
