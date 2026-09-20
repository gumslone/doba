<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Vouchers\VoucherService;
use App\Support\Hotel\HotelSettings;
use App\Support\Money;
use App\Support\Routing\Localization;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Gift vouchers, as the guest sees them (§8, FEATURE_VOUCHERS).
 *
 * Ordering creates a voucher that is worth nothing: the hotel is paid
 * directly — a transfer, a card at the desk — and activates it, at which
 * point the PDF goes to the buyer. That keeps card data, chargebacks and
 * a second payment flow out of the smallest possible hotel's way, and it
 * is how most small houses already sell vouchers by phone.
 */
class VoucherController extends Controller
{
    public function show(Seo $seo, HotelSettings $hotel): View
    {
        $this->enabledOr404();

        $seo->title(__('vouchers.title'))
            ->description(__('vouchers.meta_description', ['hotel' => $hotel->name]))
            ->canonical(Localization::route('vouchers'))
            ->alternates(Localization::alternates('vouchers'))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb(__('vouchers.title'), Localization::route('vouchers'));

        return view('vouchers.order', [
            'amounts' => (array) config('doba.vouchers.amounts'),
            'min' => (int) config('doba.vouchers.min'),
            'max' => (int) config('doba.vouchers.max'),
        ]);
    }

    public function store(Request $request, VoucherService $vouchers): RedirectResponse
    {
        $this->enabledOr404();

        $min = (int) config('doba.vouchers.min');
        $max = (int) config('doba.vouchers.max');

        $validated = $request->validate([
            'amount' => ['nullable', 'integer'],
            'amount_other' => ['nullable', 'numeric', 'min:0'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:400'],
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_email' => ['required', 'email:rfc', 'max:254'],
            // The honeypot the contact form uses: a field no person sees.
            'website' => ['nullable', 'max:0'],
        ]);

        // A typed amount wins over a preset; whole currency in, minor units out.
        $amount = ($validated['amount_other'] ?? null) !== null && (float) $validated['amount_other'] > 0
            ? (int) round(((float) $validated['amount_other']) * 100)
            : (int) ($validated['amount'] ?? 0);

        if ($amount < $min || $amount > $max) {
            return back()->withInput()->withErrors([
                'amount' => __('vouchers.error_amount', ['min' => Money::format($min), 'max' => Money::format($max)]),
            ]);
        }

        $vouchers->order([
            'amount' => $amount,
            'buyer_name' => $validated['buyer_name'],
            'buyer_email' => $validated['buyer_email'],
            'recipient_name' => $validated['recipient_name'] ?? null,
            'message' => $validated['message'] ?? null,
            'locale' => app()->getLocale(),
        ]);

        return redirect(Localization::route('vouchers'))->with('voucher_ordered', $validated['buyer_email']);
    }

    protected function enabledOr404(): void
    {
        abort_unless((bool) config('doba.features.vouchers'), 404);
    }
}
