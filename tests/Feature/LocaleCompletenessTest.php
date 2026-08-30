<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;

/**
 * Every shipped language carries every guest-facing key (§4).
 *
 * A missing key does not error — Laravel serves the raw key string or
 * silently falls back to English, and nobody notices until a guest
 * screenshots «mail.booking_subject» in their inbox. That exact bug
 * shipped once: the confirmation subject existed in de, fr and nl and
 * not in en. This test makes the whole class impossible.
 */
it('ships the same guest-facing keys in every language', function (): void {
    $files = ['booking', 'common', 'contact', 'events', 'extras', 'invoice', 'mail', 'menu', 'promo', 'routes', 'seo', 'style'];

    // Staff-facing keys that live in otherwise guest-facing files. The
    // admin UI is English-only by design, and these ride along in
    // mail.php because that is where the mail screen looks for them.
    $staffOnly = ['test_subject', 'test_heading', 'test_body', 'test_instruction', 'test_signoff',
        'spf_note', 'dmarc_note', 'dkim_note', 'dkim_value'];

    $locales = Localization::shipped();

    expect($locales)->toContain('en', 'de', 'fr', 'nl', 'uk', 'pl');

    $flatten = function (array $arr, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($arr as $key => $value) {
            $keys = array_merge($keys, is_array($value)
                ? $flatten($value, $prefix.$key.'.')
                : [$prefix.$key]);
        }

        return $keys;
    };

    foreach ($files as $file) {
        $reference = array_diff($flatten(include lang_path("en/{$file}.php")), $staffOnly);
        sort($reference);

        foreach ($locales as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $keys = array_diff($flatten(include lang_path("{$locale}/{$file}.php")), $staffOnly);
            sort($keys);

            Assert::assertSame([], array_values(array_diff($reference, $keys)),
                "lang/{$locale}/{$file}.php is missing keys");
            Assert::assertSame([], array_values(array_diff($keys, $reference)),
                "lang/{$locale}/{$file}.php has keys en does not");
        }
    }
});

it('leaves no placeholder behind in any translation', function (): void {
    // A translated sentence that dropped its :amount tells the guest
    // "left to pay: ." — grammatically fluent and factually useless.
    foreach (Localization::shipped() as $locale) {
        foreach (glob(lang_path("{$locale}/*.php")) ?: [] as $file) {
            $name = basename($file, '.php');

            if (in_array($name, ['admin', 'install'], true)) {
                continue;
            }

            $en = include lang_path("en/{$name}.php");
            $translated = include $file;

            foreach ($en as $key => $value) {
                if (! is_string($value) || ! isset($translated[$key]) || ! is_string($translated[$key])) {
                    continue;
                }

                preg_match_all('/:[a-z_]+/', $value, $m);

                foreach (array_unique($m[0]) as $placeholder) {
                    Assert::assertStringContainsString($placeholder, $translated[$key],
                        "lang/{$locale}/{$name}.php [{$key}] lost the {$placeholder} placeholder");
                }
            }
        }
    }
});

it('still resolves a stored translation after the hotel changes its languages', function (): void {
    // Stored while the hotel served de+en; the hotel then switches to
    // uk+en. Before the fix, this returned the raw array and put a 500
    // in the footer of every page — found the first time a language was
    // actually switched, which is exactly when nobody is watching for it.
    Setting::put('general', 'tagline', ['de' => 'Ruhe über dem Tal', 'en' => 'Calm above the valley'], true);

    config()->set('doba.locales', ['uk', 'en']);
    app()->setLocale('uk');
    app(HotelSettings::class)->refresh();

    // No Ukrainian version exists yet, so the fallback locale answers —
    // a sentence, never an array.
    expect(app(HotelSettings::class)->get('general.tagline'))
        ->toBe('Calm above the valley');

    // And a genuine list setting is still a list, not a translation.
    Setting::put('amenities', 'list', ['wifi', 'parking']);
    app(HotelSettings::class)->refresh();

    expect(app(HotelSettings::class)->get('amenities.list'))
        ->toBe(['wifi', 'parking']);
});

it('lets the wizard offer every shipped language and stage it into the env', function (): void {
    // The wizard is only reachable on an uninstalled copy.
    File::delete(config('doba.install.lock_path'));
    DB::table('installations')->delete();

    $this->withSession(['install_token_ok' => true])
        ->get('/install/language')
        ->assertOk()
        // Every language the software ships, not only the configured four.
        ->assertSee('value="uk"', false)
        ->assertSee('value="pl"', false);

    $this->withSession(['install_token_ok' => true])
        ->post('/install/language', ['locale' => 'uk'])
        ->assertRedirect('/install/requirements');

    // Staged for the finish step: the chosen language leads the list, so
    // it becomes the site's default locale.
    expect(session('install_env')['DOBA_LOCALES'])->toStartWith('uk,')
        ->and(session('install_env')['APP_LOCALE'])->toBe('uk');
});
