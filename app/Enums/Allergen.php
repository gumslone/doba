<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The fourteen allergens EU Regulation 1169/2011 requires a food business
 * to declare.
 *
 * A fixed enum rather than free text on purpose: "nuts", "Nüsse" and
 * "may contain traces of nuts" typed by three different chefs are not
 * searchable, not translatable and not trustworthy to a guest whose
 * reaction is measured in minutes.
 */
enum Allergen: string
{
    case Gluten = 'gluten';
    case Crustaceans = 'crustaceans';
    case Eggs = 'eggs';
    case Fish = 'fish';
    case Peanuts = 'peanuts';
    case Soybeans = 'soybeans';
    case Milk = 'milk';
    case Nuts = 'nuts';
    case Celery = 'celery';
    case Mustard = 'mustard';
    case Sesame = 'sesame';
    case Sulphites = 'sulphites';
    case Lupin = 'lupin';
    case Molluscs = 'molluscs';

    public function label(): string
    {
        return 'menu.allergen_'.$this->value;
    }

    /**
     * The number German and Austrian menus print beside a dish, which
     * guests there already know how to read.
     */
    public function number(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
