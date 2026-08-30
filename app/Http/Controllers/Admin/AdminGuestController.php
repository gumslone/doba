<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Guests\GuestPrivacy;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who actually stays here (§12).
 *
 * One profile per address, built by the booking engine as stays land.
 * The list answers the hotelier's questions in the order they ask them
 * — who is this, have they been before, what are they worth — and the
 * detail page carries the two GDPR actions a European hotel will
 * eventually be asked for, so the answer to "please delete my data" is
 * a button rather than a support thread.
 */
class AdminGuestController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('admin.guests.index', [
            'q' => $q,
            'guests' => Guest::query()
                ->when($q !== '', function ($query) use ($q): void {
                    $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

                    $query->where(fn ($w) => $w
                        ->where('email', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like));
                })
                // The regulars first: the list a hotelier actually wants
                // is "who keeps coming back", not the alphabet.
                ->orderByDesc('stays_count')
                ->orderByDesc('total_spent')
                ->paginate(50)
                ->withQueryString(),
            'consenting' => Guest::query()
                ->where('marketing_consent', true)
                ->whereNull('anonymised_at')
                ->count(),
        ]);
    }

    public function show(Guest $guest): View
    {
        return view('admin.guests.show', [
            'guest' => $guest,
            'bookings' => $guest->bookings()
                ->with(['rooms.roomType.translations', 'invoice'])
                ->orderByDesc('check_in')
                ->get(),
        ]);
    }

    public function update(Request $request, Guest $guest): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
            'marketing_consent' => ['nullable', 'boolean'],
        ]);

        if ($guest->isAnonymised()) {
            // There is no person left to annotate or subscribe.
            return back()->with('update_error', __('admin.guest_is_anonymised'));
        }

        $guest->forceFill([
            'notes' => $validated['notes'] ?? null,
            // Staff can WITHDRAW consent on a guest's behalf — the phone
            // call "stop emailing me" — but never grant it: consent is
            // the guest's own checkbox at checkout, or it is nothing.
            'marketing_consent' => $guest->marketing_consent && (bool) ($validated['marketing_consent'] ?? false),
        ])->save();

        return back()->with('saved', __('admin.guest_saved'));
    }

    /**
     * GDPR export: everything, as a file the guest can be handed.
     */
    public function export(Guest $guest, GuestPrivacy $privacy): StreamedResponse
    {
        $data = $privacy->export($guest);

        return response()->streamDownload(function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, 'guest-'.$guest->id.'-export.json', ['Content-Type' => 'application/json']);
    }

    /**
     * GDPR erasure: the person goes, the books stay.
     */
    public function erase(Request $request, Guest $guest, GuestPrivacy $privacy): RedirectResponse
    {
        $request->validate([
            // Typed out in full: this is the one action on this page that
            // cannot be undone, and a mis-click must not be able to do it.
            'confirm' => ['required', 'in:ERASE'],
        ]);

        try {
            $privacy->erase($guest);
        } catch (InvalidArgumentException $e) {
            return back()->with('update_error', $e->getMessage());
        }

        return redirect('/admin/guests')->with('saved', __('admin.guest_erased'));
    }

    /**
     * The consenting addresses, as CSV for whatever sends the newsletter.
     *
     * Only guests who ticked the box themselves and still have a self to
     * have ticked it: consent does not survive anonymisation.
     */
    public function consenting(): StreamedResponse
    {
        $guests = Guest::query()
            ->where('marketing_consent', true)
            ->whereNull('anonymised_at')
            ->orderBy('email')
            ->get(['email', 'first_name', 'last_name', 'locale']);

        return response()->streamDownload(function () use ($guests): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'first_name', 'last_name', 'locale']);

            foreach ($guests as $guest) {
                fputcsv($out, [$guest->email, $guest->first_name, $guest->last_name, $guest->locale]);
            }

            fclose($out);
        }, 'marketing-consent.csv', ['Content-Type' => 'text/csv']);
    }
}
