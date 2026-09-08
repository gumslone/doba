<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\Wording;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The words in the guest mails (§13).
 *
 * One text per language per mail, with the shipped wording shown as the
 * starting point and restored by emptying the box. Stored as the same
 * locale-keyed maps every other translatable setting uses, and never
 * touching a language the form did not show.
 */
class AdminMailWordingController extends Controller
{
    public function edit(): View
    {
        $locales = Localization::locales();
        $texts = [];

        foreach (array_keys(Wording::KEYS) as $key) {
            foreach ($locales as $locale) {
                $texts[$key][$locale] = [
                    'custom' => Wording::custom($key, $locale),
                    'shipped' => Wording::shipped($key, $locale),
                ];
            }
        }

        return view('admin.mail.wording', [
            'keys' => Wording::KEYS,
            'locales' => $locales,
            'texts' => $texts,
        ]);
    }

    public function update(Request $request, HotelSettings $hotel): RedirectResponse
    {
        $validated = $request->validate([
            'texts' => ['nullable', 'array'],
            'texts.*' => ['nullable', 'array'],
            'texts.*.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $locales = Localization::locales();
        $existing = $hotel->all();

        foreach (array_keys(Wording::KEYS) as $key) {
            $current = $existing[Wording::GROUP.'.'.$key] ?? [];
            $current = is_array($current) ? $current : [];

            foreach ($locales as $locale) {
                $text = trim((string) ($validated['texts'][$key][$locale] ?? ''));

                // Empty means "the shipped text, please" — and the same
                // text as shipped is not worth storing either, or a later
                // improvement to the shipped wording would never arrive.
                if ($text === '' || $text === Wording::shipped($key, $locale)) {
                    unset($current[$locale]);
                } else {
                    $current[$locale] = $text;
                }
            }

            Setting::put(Wording::GROUP, $key, $current === [] ? null : $current, true);
        }

        HotelSettings::flush();
        $hotel->refresh();

        return redirect('/admin/mail/wording')->with('saved', __('admin.mail_wording_saved'));
    }
}
