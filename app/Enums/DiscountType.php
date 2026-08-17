<?php

declare(strict_types=1);

namespace App\Enums;

enum DiscountType: string
{
    /** value is basis points: 1000 = 10%. No float in the money path. */
    case Percent = 'percent';

    /** value is minor units off the stay. */
    case Fixed = 'fixed';

    /** value is a number of nights, the cheapest ones first. */
    case FreeNights = 'free_nights';

    public function label(): string
    {
        return 'promo.type_'.$this->value;
    }
}
