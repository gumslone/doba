<?php

declare(strict_types=1);

namespace App\Support\Routing;

use Illuminate\Http\Request;
use Locale;

/**
 * The language of the admin panel (§12).
 *
 * Separate from the site's languages on purpose. Which languages GUESTS
 * are served is a business decision (DOBA_LOCALES); which language a
 * member of STAFF reads is theirs alone, and may be one the hotel does
 * not publish in at all. So the choice is every language that ships an
 * admin translation, and it is remembered per person.
 */
final class AdminLocale
{
    public const SESSION_KEY = 'admin_locale';

    /**
     * @return array<string,string> code => the language's own name
     */
    public static function available(): array
    {
        $names = [];

        foreach (Localization::shipped() as $locale) {
            if ($locale === 'en' || is_file(lang_path($locale.'/admin.php'))) {
                $names[$locale] = mb_convert_case((string) Locale::getDisplayLanguage($locale, $locale), MB_CASE_TITLE, 'UTF-8');
            }
        }

        return $names;
    }

    /**
     * What this request should be read in: the person's own choice, then
     * what they picked on the login screen, then the hotel's main
     * language if the admin speaks it, then English.
     */
    public static function resolve(Request $request): string
    {
        $available = array_keys(self::available());

        $candidates = [
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null,
            Localization::defaultLocale(),
            'en',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return 'en';
    }
}
