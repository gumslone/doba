<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Hotel\HotelSettings;
use App\Support\Install\EnvWriter;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The hotel's own details (§12).
 *
 * Everything the install wizard asked once and nothing could change
 * since: name, address, coordinates, the tagline and SEO texts in every
 * language, policy wording, the socials, the VAT id, and the four
 * things that live in .env because the runtime reads them at boot —
 * timezone, currency, check-in and check-out times.
 *
 * Translatable fields render one input per language the hotel serves,
 * and are stored as the same locale-keyed maps the wizard and the seeder
 * write, so HotelSettings resolves them exactly as before.
 */
class AdminSettingsController extends Controller
{
    /** Plain, one value for every language. */
    private const PLAIN = [
        'general' => ['name', 'star_rating', 'since'],
        'contact' => ['email', 'phone', 'street', 'postal_code', 'city', 'country', 'latitude', 'longitude'],
        'social' => ['facebook', 'instagram', 'tripadvisor'],
        'tax' => ['vat_id', 'accommodation_rate'],
        'analytics' => ['id'],
    ];

    /** One value per language. */
    private const TRANSLATABLE = [
        'general' => ['tagline'],
        'seo' => ['title', 'description'],
        'policy' => ['cancellation'],
    ];

    public function edit(HotelSettings $hotel): View
    {
        $all = $hotel->all();

        return view('admin.settings.index', [
            'values' => $all,
            'locales' => Localization::locales(),
            'timezones' => timezone_identifiers_list(),
            'usps' => is_array($all['general.usps'] ?? null) ? $all['general.usps'] : [],
            'env' => [
                'timezone' => (string) config('app.timezone'),
                'currency' => (string) config('doba.currency'),
                'checkin_from' => (string) config('doba.checkin_from'),
                'checkout_until' => (string) config('doba.checkout_until'),
            ],
        ]);
    }

    public function update(Request $request, HotelSettings $hotel): RedirectResponse
    {
        $locales = Localization::locales();

        $validated = $request->validate([
            'general.name' => ['required', 'string', 'max:255'],
            'general.star_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'general.since' => ['nullable', 'integer', 'min:1000', 'max:2100'],
            'contact.email' => ['required', 'email:rfc', 'max:254'],
            'contact.phone' => ['nullable', 'string', 'max:64'],
            'contact.street' => ['nullable', 'string', 'max:255'],
            'contact.postal_code' => ['nullable', 'string', 'max:32'],
            'contact.city' => ['nullable', 'string', 'max:255'],
            'contact.country' => ['nullable', 'string', 'max:64'],
            'contact.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'contact.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'social.facebook' => ['nullable', 'url', 'max:255'],
            'social.instagram' => ['nullable', 'url', 'max:255'],
            'social.tripadvisor' => ['nullable', 'url', 'max:255'],
            'tax.vat_id' => ['nullable', 'string', 'max:32'],
            'tax.accommodation_rate' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'tax.cleaning_rate' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'analytics.id' => ['nullable', 'string', 'max:64'],
            'translations' => ['nullable', 'array'],
            'translations.*' => ['array'],
            'translations.*.*' => ['nullable', 'string', 'max:2000'],
            'usps' => ['nullable', 'array', 'max:6'],
            'usps.*.icon' => ['nullable', 'string', 'max:32', 'alpha_dash'],
            'usps.*.title' => ['nullable', 'string', 'max:80'],
            'usps.*.subtitle' => ['nullable', 'string', 'max:120'],
            'env.timezone' => ['required', 'timezone'],
            'env.currency' => ['required', 'string', 'size:3', 'alpha'],
            'env.checkin_from' => ['required', 'date_format:H:i'],
            'env.checkout_until' => ['required', 'date_format:H:i'],
        ]);

        foreach (self::PLAIN as $group => $keys) {
            foreach ($keys as $key) {
                $value = $validated[$group][$key] ?? null;
                Setting::put($group, $key, $value === '' ? null : $value);
            }
        }

        // Stored as locale-keyed maps, exactly as the wizard and the seeder
        // write them — never overwriting a language the form did not
        // show, so switching the site's languages cannot erase a text.
        $existing = $hotel->all();

        foreach (self::TRANSLATABLE as $group => $keys) {
            foreach ($keys as $key) {
                $current = $existing["{$group}.{$key}"] ?? [];
                // A value stored before the field was translatable is a
                // bare string: it becomes the default language's entry.
                $current = is_array($current)
                    ? $current
                    : (is_scalar($current) && (string) $current !== '' ? [$locales[0] => (string) $current] : []);

                foreach ($locales as $locale) {
                    $text = trim((string) ($validated['translations']["{$group}.{$key}"][$locale] ?? ''));

                    if ($text === '') {
                        unset($current[$locale]);
                    } else {
                        $current[$locale] = $text;
                    }
                }

                Setting::put($group, $key, $current === [] ? null : $current, true);
            }
        }

        $usps = array_values(array_filter(
            array_map(static fn (array $row): array => [
                'icon' => (string) ($row['icon'] ?? 'check'),
                'title' => trim((string) ($row['title'] ?? '')),
                'subtitle' => trim((string) ($row['subtitle'] ?? '')),
            ], $validated['usps'] ?? []),
            static fn (array $row): bool => $row['title'] !== '',
        ));

        Setting::put('general', 'usps', $usps);

        // The four runtime values. Written to .env because config reads
        // them at boot, and applied to the running config too so this
        // very response already renders the new check-in time.
        $env = [
            'APP_TIMEZONE' => $validated['env']['timezone'],
            'DOBA_CURRENCY' => strtoupper($validated['env']['currency']),
            'DOBA_CHECKIN_FROM' => $validated['env']['checkin_from'],
            'DOBA_CHECKOUT_UNTIL' => $validated['env']['checkout_until'],
        ];

        try {
            EnvWriter::make()->write($env);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['env.timezone' => $e->getMessage()]);
        }

        config([
            'app.timezone' => $env['APP_TIMEZONE'],
            'doba.currency' => $env['DOBA_CURRENCY'],
            'doba.checkin_from' => $env['DOBA_CHECKIN_FROM'],
            'doba.checkout_until' => $env['DOBA_CHECKOUT_UNTIL'],
        ]);

        $hotel->refresh();

        return redirect('/admin/settings')->with('saved', __('admin.settings_saved'));
    }
}
