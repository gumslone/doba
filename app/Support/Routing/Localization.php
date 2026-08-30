<?php

declare(strict_types=1);

namespace App\Support\Routing;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Locale-aware URL building.
 *
 * Two things have to be per-locale for the site to be indexable in four
 * languages at once:
 *
 *   1. the *path segment* — /de/zimmer vs /en/rooms — which is a lang/ string;
 *   2. the *slug* — /de/zimmer/doppelzimmer vs /en/rooms/double-room — which
 *      lives in the translation table, never on the parent (§5).
 *
 * Routes are registered once per locale with the locale as a name prefix
 * ("de.rooms.show"), so building the alternate of the current page is a
 * matter of asking each locale for its own name and its own parameters.
 */
final class Localization
{
    /**
     * Segments that can never be a CMS page slug, because something else
     * already owns that first path segment.
     */
    public const RESERVED = [
        'api', 'install', 'admin', 'webhooks', 'ical', 'storage',
        'up', 'sitemap.xml', 'robots.txt', 'livewire', 'build',
    ];

    /**
     * @return array<int,string>
     */
    public static function locales(): array
    {
        $locales = (array) config('doba.locales', []);

        return $locales === [] ? [config('app.locale')] : array_values($locales);
    }

    /**
     * The languages the SOFTWARE ships, as opposed to the ones this
     * hotel serves. Read off the lang directory rather than a constant,
     * so dropping a translated set into lang/ is the whole act of adding
     * a language — and the install wizard offers every language a
     * hotelier could actually run, not just the four in the default env.
     *
     * @return array<int,string>
     */
    public static function shipped(): array
    {
        $locales = [];

        foreach (glob(base_path('lang/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            // booking.php is the file no guest-facing locale can lack.
            if (is_file($dir.'/booking.php')) {
                $locales[] = basename($dir);
            }
        }

        sort($locales);

        // English first: it is the fallback locale and the one set every
        // installation carries complete.
        usort($locales, static fn (string $a, string $b): int => ($a === 'en' ? 0 : 1) <=> ($b === 'en' ? 0 : 1));

        return $locales;
    }

    public static function defaultLocale(): string
    {
        return self::locales()[0];
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::locales(), true);
    }

    /**
     * The URL prefix for a locale — empty for the default locale when
     * DOBA_HIDE_DEFAULT_PREFIX is on.
     */
    public static function prefix(string $locale): string
    {
        return $locale === self::defaultLocale() && config('doba.hide_default_prefix')
            ? ''
            : $locale;
    }

    /**
     * A translated path segment: segment('rooms', 'de') === 'zimmer'.
     *
     * Falls back to the key itself, so adding a locale before its routes.php
     * exists yields a working (if untranslated) URL rather than an exception
     * during a page render.
     */
    public static function segment(string $key, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $translated = trans('routes.'.$key, [], $locale);

        return is_string($translated) && $translated !== 'routes.'.$key
            ? $translated
            : $key;
    }

    /**
     * Build a URL for a named route in a specific locale.
     *
     * @param  array<string,mixed>  $parameters
     */
    public static function route(string $name, array $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        $locale ??= app()->getLocale();

        return URL::route($locale.'.'.$name, $parameters, $absolute);
    }

    public static function hasRoute(string $name, ?string $locale = null): bool
    {
        $locale ??= app()->getLocale();

        return Route::has($locale.'.'.$name);
    }

    /**
     * The hreflang map for a page.
     *
     * $parameters is called once per locale and returns that locale's route
     * parameters, or null when the page does not exist in that language —
     * an alternate pointing at a fallback-rendered page is worse than no
     * alternate, because it tells Google two URLs are translations of each
     * other when one of them is not.
     *
     * @param  callable(string):(array<string,mixed>|null)|array<string,mixed>  $parameters
     * @return array<string,string> locale => absolute URL
     */
    public static function alternates(string $name, callable|array $parameters = []): array
    {
        $alternates = [];

        foreach (self::locales() as $locale) {
            if (! self::hasRoute($name, $locale)) {
                continue;
            }

            $params = is_callable($parameters) ? $parameters($locale) : $parameters;

            if ($params === null) {
                continue;
            }

            $alternates[$locale] = self::route($name, $params, $locale);
        }

        return $alternates;
    }

    /**
     * The x-default target: the default locale's URL when it exists,
     * otherwise the first alternate we do have.
     *
     * @param  array<string,string>  $alternates
     */
    public static function xDefault(array $alternates): ?string
    {
        return $alternates[self::defaultLocale()] ?? (reset($alternates) ?: null);
    }

    /**
     * BCP 47 tag for the html lang attribute and hreflang values.
     * 'uk' stays 'uk'; a region-qualified locale like 'de_AT' becomes 'de-AT'.
     */
    public static function bcp47(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }
}
