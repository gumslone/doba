<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Content translation, layer 2 of §5: room names, page bodies, policies and
 * slugs live in a *_translations table so the hotelier edits them in the
 * admin panel. (Layer 1 — interface strings — is lang/ files, and the two
 * are never mixed.)
 *
 * The model using this trait declares:
 *
 *     protected string $translationModel = RoomTypeTranslation::class;
 *     protected string $translationForeignKey = 'room_type_id';
 *
 * @phpstan-require-extends Model
 *
 * @property-read Collection<int, Translation> $translations
 */
trait HasTranslations
{
    /**
     * @return HasMany<Translation, $this>
     */
    public function translations(): HasMany
    {
        /** @var class-string<Translation> $model */
        $model = $this->translationModel;

        return $this->hasMany($model, $this->translationForeignKey);
    }

    /**
     * Eager-loadable single translation for the active locale, used by index
     * pages so listing twenty rooms is two queries and not twenty-one.
     *
     * @return HasOne<Translation, $this>
     */
    public function translation(): HasOne
    {
        /** @var class-string<Translation> $model */
        $model = $this->translationModel;

        return $this->hasOne($model, $this->translationForeignKey)
            ->where('locale', app()->getLocale());
    }

    /**
     * Read a translated field, falling back to APP_FALLBACK_LOCALE.
     *
     * The fallback is deliberate for prose and deliberate*ly absent* for
     * slugs: see slug(), where falling back would produce two locales
     * claiming the same URL.
     */
    public function t(string $field, ?string $locale = null, bool $fallback = true): ?string
    {
        $locale ??= app()->getLocale();

        $value = $this->translationFor($locale)?->getAttribute($field);

        if (($value === null || $value === '') && $fallback) {
            $value = $this->translationFor((string) config('app.fallback_locale'))?->getAttribute($field);
        }

        return $value === '' || $value === null ? null : (string) $value;
    }

    /**
     * The routable slug for a locale — never falls back.
     *
     * A missing translation means the page does not exist in that language,
     * and the caller (hreflang, sitemap) must be able to tell the difference
     * between "not translated" and "translated to the same words".
     */
    public function slug(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $this->translationFor($locale)?->slug;
    }

    public function isTranslatedInto(string $locale): bool
    {
        return $this->slug($locale) !== null;
    }

    /**
     * Locales this record is actually published in — the input to both the
     * hreflang set and the sitemap's per-locale alternate links.
     *
     * @return array<int,string>
     */
    public function translatedLocales(): array
    {
        return $this->translations
            ->pluck('locale')
            ->filter(fn (string $locale): bool => $this->slug($locale) !== null)
            ->values()
            ->all();
    }

    protected function translationFor(string $locale): ?Translation
    {
        // Prefer an already-loaded single translation so an index page that
        // eager-loaded 'translation' does not fire a query per row.
        if ($this->relationLoaded('translation')) {
            $loaded = $this->getRelation('translation');

            if ($loaded instanceof Translation && $loaded->locale === $locale) {
                return $loaded;
            }
        }

        return $this->translations->firstWhere('locale', $locale);
    }
}
