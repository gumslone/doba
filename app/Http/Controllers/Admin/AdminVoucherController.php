<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Vouchers\VoucherException;
use App\Domain\Vouchers\VoucherRenderer;
use App\Domain\Vouchers\VoucherService;
use App\Http\Controllers\Controller;
use App\Mail\VoucherIssued;
use App\Models\GiftVoucher;
use App\Models\Setting;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Gift vouchers at the desk (§8).
 *
 * Orders from the website wait here until the money has arrived; one
 * click makes them worth something and mails the PDF. Vouchers sold over
 * the counter are created here and are active at once. A voucher's
 * balance is only ever changed by a redemption or a refund — there is no
 * "edit balance", because a number somebody can type is a number nobody
 * can audit.
 */
class AdminVoucherController extends Controller
{
    public function index(HotelSettings $hotel): View
    {
        return view('admin.vouchers.index', [
            'pending' => GiftVoucher::query()->where('status', GiftVoucher::PENDING)->latest()->get(),
            'vouchers' => GiftVoucher::query()->where('status', '!=', GiftVoucher::PENDING)->with('redemptions.booking')->latest()->paginate(40),
            'outstanding' => (int) GiftVoucher::query()->where('status', GiftVoucher::ACTIVE)->sum('balance'),
            'enabled' => (bool) config('doba.features.vouchers'),
            'instructions' => (string) $hotel->get('vouchers.payment_instructions', ''),
            'locales' => Localization::locales(),
        ]);
    }

    public function store(Request $request, VoucherService $vouchers): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_email' => ['nullable', 'email:rfc', 'max:254'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:400'],
            'locale' => ['required', Rule::in(Localization::locales())],
            'internal_note' => ['nullable', 'string', 'max:500'],
        ]);

        $voucher = $vouchers->issue([
            ...$validated,
            // Whole currency at the desk, minor units in the books (§5).
            'amount' => (int) round(((float) $validated['amount']) * 100),
        ]);

        return redirect('/admin/vouchers')->with('saved', __('admin.voucher_issued', ['code' => $voucher->code]));
    }

    public function activate(GiftVoucher $voucher, VoucherService $vouchers): RedirectResponse
    {
        try {
            $vouchers->activate($voucher);
        } catch (VoucherException $e) {
            return back()->withErrors(['voucher' => __($e->getMessage())]);
        }

        return back()->with('saved', __('admin.voucher_activated', ['code' => $voucher->code]));
    }

    public function void(GiftVoucher $voucher, VoucherService $vouchers): RedirectResponse
    {
        $vouchers->void($voucher);

        return back()->with('saved', __('admin.voucher_voided', ['code' => $voucher->code]));
    }

    public function resend(GiftVoucher $voucher, MailSettings $mail): RedirectResponse
    {
        if ($voucher->status === GiftVoucher::PENDING || $voucher->buyer_email === null || ! $mail->isConfirmed()) {
            return back()->withErrors(['voucher' => __('admin.voucher_cannot_send')]);
        }

        Mail::to($voucher->buyer_email)->queue(new VoucherIssued($voucher));

        return back()->with('saved', __('admin.voucher_sent', ['email' => $voucher->buyer_email]));
    }

    public function download(GiftVoucher $voucher, VoucherRenderer $renderer): Response
    {
        return response($renderer->render($voucher), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$voucher->code.'.pdf"',
        ]);
    }

    /**
     * What the buyer is told about paying: bank details, a payment link,
     * "pay at the desk". Free text, because every house does it its way.
     */
    public function instructions(Request $request, HotelSettings $hotel): RedirectResponse
    {
        $validated = $request->validate(['payment_instructions' => ['nullable', 'string', 'max:2000']]);

        Setting::put('vouchers', 'payment_instructions', trim((string) ($validated['payment_instructions'] ?? '')) ?: null);
        HotelSettings::flush();
        $hotel->refresh();

        return back()->with('saved', __('admin.voucher_instructions_saved'));
    }
}
