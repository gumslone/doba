<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Directory\PropertyDescriptor;
use App\Support\Hotel\HotelSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Deciding to be listed (§21).
 *
 * The screen exists because the decision is the hotelier's. Everything
 * technical about the listing is automatic — a hub finds the descriptor,
 * reads it, prices dates against the live search — but *whether this
 * hotel appears in a directory at all* is a business decision, and
 * nothing in an operator's `.env` should make it on their behalf.
 *
 * So the page leads with what would be published, not with a switch. A
 * hotelier who can read the document before turning it on is one who can
 * consent to it.
 */
class AdminDirectoryController extends Controller
{
    public function edit(HotelSettings $hotel, PropertyDescriptor $descriptor): View
    {
        return view('admin.directory.index', [
            'enabled' => PropertyDescriptor::isEnabled(),
            'hub' => PropertyDescriptor::hub(),
            'lastAnnounce' => $hotel->get('directory.last_announce'),
            'httpsUrl' => str_starts_with((string) config('app.url'), 'https://'),
            'hasCoordinates' => $hotel->hasCoordinates(),
            // Exactly what a hub would read, formatted to be read by a
            // person: consent to a document you cannot see is not consent.
            'preview' => json_encode(
                $descriptor->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ]);
    }

    public function update(Request $request, HotelSettings $hotel): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'hub' => ['required', 'url:https', 'max:255'],
        ]);

        Setting::put('directory', 'enabled', (bool) ($validated['enabled'] ?? false));
        Setting::put('directory', 'hub', $validated['hub']);

        $hotel->refresh();

        return redirect('/admin/directory')->with('saved', __('admin.directory_saved'));
    }

    /**
     * Announce now, rather than waiting for tonight.
     */
    public function announce(): RedirectResponse
    {
        $exit = Artisan::call('doba:directory:announce');

        return redirect('/admin/directory')->with(
            $exit === 0 ? 'saved' : 'update_error',
            $exit === 0 ? __('admin.directory_announced') : trim(Artisan::output()),
        );
    }
}
