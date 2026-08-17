<?php

declare(strict_types=1);

namespace App\Support\Hotel;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The $hotel object shared with every view.
 *
 * Reads the settings table once per request (cached across requests, busted
 * on write) and exposes it by dotted "group.key". Translatable settings —
 * policy texts, the SEO description, the tagline — resolve against the
 * active locale with a fallback to APP_FALLBACK_LOCALE.
 */
class HotelSettings
{
    public const CACHE_KEY = 'doba:settings';

    /** @var array<string,mixed>|null */
    protected ?array $values = null;

    public string $name;

    public function __construct()
    {
        $this->name = (string) ($this->get('general.name') ?: config('app.name'));
    }

    /**
     * Drop the in-memory copy so the next read comes from the store.
     *
     * `Setting::put` busts the shared cache, but nothing bust the copy
     * held on this object — so anywhere the container outlives a request
     * (an Octane worker, a queue worker) a hotelier could save a setting
     * and watch the site keep serving the old value until the process was
     * restarted. Called as each request picks up its route.
     */
    public function refresh(): void
    {
        $this->values = null;
        $this->name = (string) ($this->get('general.name') ?: config('app.name'));
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->values ??= Cache::rememberForever(
            self::CACHE_KEY,
            static function (): array {
                // A settings read must not be what breaks a fresh clone before
                // the first migration has run — the installer (§16) serves the
                // wizard through the same layout.
                if (! Setting::tableExists()) {
                    return [];
                }

                return Setting::query()
                    ->get()
                    ->mapWithKeys(static fn (Setting $setting): array => [
                        $setting->group.'.'.$setting->key => $setting->value,
                    ])
                    ->all();
            }
        );
    }

    /**
     * Get a setting by "group.key". Translatable values are stored as
     * ['de' => '…', 'en' => '…'] and resolved here.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_array($value) && $this->looksTranslatable($value)) {
            return $value[app()->getLocale()]
                ?? $value[config('app.fallback_locale')]
                ?? reset($value)
                ?: $default;
        }

        return $value;
    }

    public function hasCoordinates(): bool
    {
        return $this->get('contact.latitude') !== null
            && $this->get('contact.longitude') !== null;
    }

    /**
     * Absolute URL of the social sharing image, falling back to the config
     * default. Returns null rather than a broken URL when neither is set —
     * an og:image pointing at a 404 is worse than no og:image.
     */
    public function ogImage(): ?string
    {
        $path = $this->get('branding.og_image') ?: config('doba.seo.og_image');

        if (! $path) {
            return null;
        }

        return str_starts_with((string) $path, 'http')
            ? (string) $path
            : Storage::disk('public')->url((string) $path);
    }

    public function logo(): ?string
    {
        $path = $this->get('branding.logo');

        return $path ? Storage::disk('public')->url((string) $path) : null;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * A translatable value is a map whose every key is a configured locale.
     * Checking the keys — rather than "is it an array" — is what keeps a
     * genuine list setting (amenities.list) from being mistaken for one.
     *
     * @param  array<mixed>  $value
     */
    protected function looksTranslatable(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        $locales = (array) config('doba.locales', []);

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $locales, true)) {
                return false;
            }
        }

        return true;
    }
}
