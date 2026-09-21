<?php

declare(strict_types=1);

namespace App\Support;

use Collator;
use ResourceBundle;

/**
 * Country names in the guest's language, from the ICU data the intl
 * extension already ships — no package, no list to keep current.
 */
final class Countries
{
    /** Codes ICU lists under Countries that are not places a person is from. */
    private const NOT_COUNTRIES = ['EU', 'EZ', 'UN', 'ZZ', 'XA', 'XB', 'QO', 'AC', 'CP', 'CQ', 'DG', 'EA', 'IC', 'TA'];

    /**
     * @return array<string,string> ISO 3166-1 alpha-2 => name, sorted for the locale
     */
    public static function names(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        static $cache = [];

        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        $names = [];
        $bundle = ResourceBundle::create($locale, 'ICUDATA-region');
        $countries = $bundle?->get('Countries');

        if ($countries instanceof ResourceBundle) {
            foreach ($countries as $code => $name) {
                if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, self::NOT_COUNTRIES, true)) {
                    $names[$code] = (string) $name;
                }
            }
        }

        if ($names === []) {
            return $cache[$locale] = ['DE' => 'Germany', 'AT' => 'Austria', 'CH' => 'Switzerland', 'PL' => 'Poland', 'UA' => 'Ukraine', 'NL' => 'Netherlands', 'FR' => 'France', 'GB' => 'United Kingdom', 'US' => 'United States'];
        }

        $collator = new Collator($locale);
        uasort($names, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $cache[$locale] = $names;
    }

    public static function name(?string $code, ?string $locale = null): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::names($locale)[strtoupper($code)] ?? strtoupper($code);
    }
}
