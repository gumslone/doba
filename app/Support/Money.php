<?php

declare(strict_types=1);

namespace App\Support;

use NumberFormatter;

/**
 * Money is stored and passed around as integer minor units (§5) and turned
 * into a string only at the edges — here, and in the JSON-LD Offer.
 *
 * Formatting is locale-aware via intl, because "€125.00" in a German page
 * is wrong twice over: the separator and the position of the symbol.
 */
final class Money
{
    public static function format(?int $minor, ?string $currency = null, ?string $locale = null): ?string
    {
        if ($minor === null) {
            return null;
        }

        $currency ??= (string) config('doba.currency', 'EUR');
        $locale ??= app()->getLocale();

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        // Nightly rates are whole euros far more often than not, and a price
        // badge reading "ab 125 €" is easier to scan than "ab 125,00 €".
        if ($minor % 100 === 0) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        }

        return $formatter->formatCurrency($minor / 100, $currency) ?: null;
    }
}
