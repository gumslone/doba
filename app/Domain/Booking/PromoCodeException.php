<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use RuntimeException;

/**
 * A promo code that was valid when the guest typed it and is not valid
 * now — used up, deactivated, or outside its window by the time checkout
 * completed.
 *
 * Carries the translation key rather than a sentence, so the guest is
 * told what is actually wrong in their own language.
 */
class PromoCodeException extends RuntimeException
{
    public function __construct(public readonly string $reasonKey)
    {
        parent::__construct($reasonKey);
    }
}
