<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a dish is suitable for. Distinct from allergens: an allergen is a
 * warning the law requires, a diet is a preference the guest filters by,
 * and conflating them puts "contains milk" and "vegetarian" in one list.
 */
enum Diet: string
{
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';
    case GlutenFree = 'gluten_free';
    case LactoseFree = 'lactose_free';
    case Halal = 'halal';
    case Kosher = 'kosher';

    public function label(): string
    {
        return 'menu.diet_'.$this->value;
    }

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
