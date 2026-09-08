<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EnquiryStatus;
use App\Http\Controllers\Controller;
use App\Mail\EnquiryReply;
use App\Models\Enquiry;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use App\Support\Routing\Localization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * The enquiries inbox (§12).
 *
 * Every contact-form message has been stored since day one and mailed
 * to the hotel's inbox; until now the panel only counted them. This is
 * where they are read, answered and — when the filter guessed wrong —
 * rescued from spam. The answer goes out as the hotel's own mail and is
 * kept on the enquiry, so "what did we tell them" has an answer too.
 */
class AdminEnquiryController extends Controller
{
    public function index(Request $request): View
    {
        $filter = (string) $request->query('status', 'inbox');

        $query = Enquiry::query()->with('repliedBy')->latest();

        match ($filter) {
            'new' => $query->where('status', EnquiryStatus::New->value),
            'replied' => $query->where('status', EnquiryStatus::Replied->value),
            'spam' => $query->where('status', EnquiryStatus::Spam->value),
            'all' => $query,
            default => $query->inbox(),
        };

        /** @var array<string,int> $counts */
        $counts = Enquiry::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(static fn ($n): int => (int) $n)
            ->all();

        return view('admin.enquiries.index', [
            'enquiries' => $query->paginate(30)->withQueryString(),
            'filter' => in_array($filter, ['new', 'replied', 'spam', 'all'], true) ? $filter : 'inbox',
            'counts' => $counts,
        ]);
    }

    public function show(Enquiry $enquiry, HotelSettings $hotel, MailSettings $mail): View
    {
        // Opening it is reading it. The badge in the sidebar counts what
        // nobody has looked at yet, not what nobody has answered.
        if ($enquiry->status === EnquiryStatus::New) {
            $enquiry->forceFill(['status' => EnquiryStatus::Read])->save();
        }

        $searchUrl = null;

        if ($enquiry->check_in !== null && $enquiry->check_out !== null) {
            // Straight to the public search for the dates asked about,
            // so the answer can quote a real price and a real room.
            $searchUrl = Localization::route('booking.search', [
                'check_in' => $enquiry->check_in->toDateString(),
                'check_out' => $enquiry->check_out->toDateString(),
            ]);
        }

        return view('admin.enquiries.show', [
            'enquiry' => $enquiry->load('repliedBy'),
            'guest' => $enquiry->knownGuest(),
            'mailConfirmed' => $mail->isConfirmed(),
            'searchUrl' => $searchUrl,
            // In the guest's language, whatever the panel is set to: the
            // subject line is the one thing the hotelier does not write.
            'defaultSubject' => __('contact.reply_subject', ['hotel' => $hotel->name], $enquiry->locale),
        ]);
    }

    public function reply(Request $request, Enquiry $enquiry, HotelSettings $hotel, MailSettings $mail): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        // Refused, not queued-and-lost: an outgoing mail on an install
        // that never confirmed its mail settings fails silently, and the
        // hotelier would believe the guest was answered.
        if (! $mail->isConfirmed()) {
            return back()->withInput()->withErrors(['body' => __('admin.enquiry_mail_unconfirmed')]);
        }

        Mail::to($enquiry->email)->queue(new EnquiryReply(
            $enquiry,
            trim($validated['subject']),
            trim($validated['body']),
            $hotel->name,
            (string) $hotel->get('contact.email') ?: null,
        ));

        $enquiry->forceFill([
            'status' => EnquiryStatus::Replied,
            'reply' => trim($validated['body']),
            'replied_at' => CarbonImmutable::now(),
            'replied_by' => $request->user()?->id,
        ])->save();

        return redirect('/admin/enquiries/'.$enquiry->id)->with('saved', __('admin.enquiry_replied'));
    }

    /**
     * Mark as spam, rescue from spam, or put it back to unread for a
     * colleague — the same small set of states the column always had.
     */
    public function status(Request $request, Enquiry $enquiry): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(EnquiryStatus::class)],
        ]);

        $enquiry->forceFill(['status' => EnquiryStatus::from($validated['status'])])->save();

        return back()->with('saved', __('admin.enquiry_status_saved'));
    }

    public function destroy(Enquiry $enquiry): RedirectResponse
    {
        $enquiry->delete();

        return redirect('/admin/enquiries')->with('saved', __('admin.enquiry_deleted'));
    }
}
