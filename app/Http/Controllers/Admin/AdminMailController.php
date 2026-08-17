<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMessage;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Outgoing mail (§13, §16 step 5).
 *
 * The important part of this screen is not the form. It is that the
 * hotel's mail is treated as broken until a human says a test message
 * arrived — because mail is the one subsystem that fails completely
 * silently. SMTP accepts the message, the queue reports success, every
 * page looks right, and the guest never receives their confirmation.
 * Nobody discovers it until somebody arrives at the desk with nothing.
 */
class AdminMailController extends Controller
{
    public function edit(MailSettings $mail): View
    {
        return view('admin.mail.index', [
            'settings' => $mail->all(),
            'hasPassword' => $mail->password() !== null,
            'confirmed' => $mail->isConfirmed(),
            'records' => $mail->dnsRecords(),
            'transports' => MailSettings::TRANSPORTS,
            'pendingCode' => session('mail_test_code'),
        ]);
    }

    public function update(Request $request, MailSettings $mail): RedirectResponse
    {
        $validated = $request->validate([
            'transport' => ['required', Rule::in(MailSettings::TRANSPORTS)],
            'host' => ['required_if:transport,smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:transport,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'from_address' => ['required', 'email:rfc', 'max:254'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $mail->save($validated);

        // Saving clears the confirmation, so the page comes back saying
        // "not confirmed" — which is the honest state after a change.
        return redirect('/admin/mail')->with('saved', __('admin.mail_saved'));
    }

    /**
     * Send a real message to a real address.
     */
    public function test(Request $request, MailSettings $mail, HotelSettings $hotel): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email:rfc', 'max:254'],
        ]);

        $mail->apply();

        // A code shown here and repeated in the message. Without it "yes,
        // it arrived" can honestly mean a test from last week, or one
        // from the staging copy of this same hotel.
        $code = Str::upper(Str::random(6));

        try {
            Mail::to($validated['to'])->send(new TestMessage($hotel->name, $code));
        } catch (Throwable $e) {
            return back()->with('mail_error', __('admin.mail_test_failed', ['error' => $e->getMessage()]));
        }

        $mail->recordTest();

        return redirect('/admin/mail')->with([
            'mail_test_code' => $code,
            'mail_test_sent' => $validated['to'],
        ]);
    }

    /**
     * The confirmation itself: a human saying the message arrived.
     */
    public function confirm(Request $request, MailSettings $mail): RedirectResponse
    {
        $validated = $request->validate([
            // Typed from the message, not a checkbox. A checkbox is a
            // thing people tick to make a warning go away; a code can
            // only come from an email that actually arrived.
            'code' => ['required', 'string'],
            'expected' => ['required', 'string'],
        ]);

        if (! hash_equals($validated['expected'], Str::upper(trim($validated['code'])))) {
            return back()
                ->with('mail_test_code', $validated['expected'])
                ->with('mail_error', __('admin.mail_code_wrong'));
        }

        $mail->confirm();

        return redirect('/admin/mail')->with('saved', __('admin.mail_confirmed'));
    }
}
