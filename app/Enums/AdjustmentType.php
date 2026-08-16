<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a rate plan moves the resolved nightly price (§7 step 4).
 *
 * Percent values are basis points (−1000 = −10%) so no fractional
 * percentage needs a float in the money path; fixed values are minor
 * units like every other amount (§5).
 */
enum AdjustmentType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
