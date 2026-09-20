<?php

declare(strict_types=1);

namespace App\Domain\Vouchers;

use App\Domain\Payments\PaymentService;
use App\Enums\BookingStatus;
use App\Mail\VoucherIssued;
use App\Mail\VoucherOrdered;
use App\Models\Booking;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherRedemption;
use App\Models\Payment;
use App\Support\Alerts\Alerts;
use App\Support\Mail\MailSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Gift vouchers (§8): sold, activated, spent, and — when a stay is
 * refunded — given their money back.
 *
 * A voucher is a means of payment. Redeeming one records a Payment
 * against the booking through the same path a card payment takes, so
 * paid_amount, balance_due, confirmation of a pending booking, webhooks
 * and refunds all behave as they already do. What this class adds is the
 * one thing a card does not have: a balance that must never be spent
 * twice, which is why every movement happens under a row lock.
 */
class VoucherService
{
    public function __construct(protected PaymentService $payments) {}

    /**
     * Ordered on the website: worth nothing until the hotel has the money.
     *
     * @param  array{amount:int,buyer_name:string,buyer_email:string,recipient_name?:string|null,message?:string|null,locale?:string|null}  $data
     */
    public function order(array $data): GiftVoucher
    {
        $voucher = $this->create($data, GiftVoucher::PENDING, 'web');

        if ($voucher->buyer_email !== null && app(MailSettings::class)->isConfirmed()) {
            Mail::to($voucher->buyer_email)->queue(new VoucherOrdered($voucher));
        }

        // Subject carries the code: alerts are throttled per subject, and
        // two orders in an hour are two things the hotel must see.
        app(Alerts::class)->send('Gift voucher ordered: '.$voucher->code, [
            Money::format($voucher->initial_amount, $voucher->currency).' for '.($voucher->recipient_name ?: '—').', ordered by '.$voucher->buyer_name.' <'.$voucher->buyer_email.'>.',
            'It is worth nothing until you mark it as paid under Admin → Gift vouchers. The buyer has been sent your payment instructions.',
        ]);

        return $voucher;
    }

    /**
     * Sold at the desk, or given as a gesture: active at once.
     *
     * @param  array{amount:int,buyer_name:string,buyer_email?:string|null,recipient_name?:string|null,message?:string|null,locale?:string|null,internal_note?:string|null}  $data
     */
    public function issue(array $data, bool $mail = true): GiftVoucher
    {
        $voucher = $this->create($data, GiftVoucher::PENDING, 'desk');

        return $this->activate($voucher, $mail);
    }

    public function activate(GiftVoucher $voucher, bool $mail = true): GiftVoucher
    {
        if ($voucher->status !== GiftVoucher::PENDING) {
            throw new VoucherException('vouchers.error_not_pending');
        }

        $years = max(1, (int) config('doba.vouchers.valid_years', 3));
        $today = CarbonImmutable::today(config('doba.timezone'));

        $voucher->forceFill([
            'status' => GiftVoucher::ACTIVE,
            'activated_at' => CarbonImmutable::now(),
            // To the end of the year, N years on — the convention a guest
            // expects and the one German law describes.
            'expires_on' => $today->addYears($years)->endOfYear()->toDateString(),
        ])->save();

        if ($mail && $voucher->buyer_email !== null && app(MailSettings::class)->isConfirmed()) {
            Mail::to($voucher->buyer_email)->queue(new VoucherIssued($voucher));
        }

        return $voucher;
    }

    /**
     * Take a voucher out of circulation. What was already spent stays
     * spent; what is left is gone.
     */
    public function void(GiftVoucher $voucher): GiftVoucher
    {
        return DB::transaction(function () use ($voucher): GiftVoucher {
            /** @var GiftVoucher $locked */
            $locked = GiftVoucher::query()->lockForUpdate()->findOrFail($voucher->id);

            $locked->forceFill(['status' => GiftVoucher::VOID, 'voided_at' => CarbonImmutable::now()])->save();

            return $locked;
        });
    }

    /**
     * Spend a voucher on a booking: as much of it as the booking still
     * owes, and no more.
     */
    public function redeem(string $typedCode, Booking $booking): GiftVoucherRedemption
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::CheckedIn], true)) {
            throw new VoucherException('vouchers.error_booking_closed');
        }

        $code = GiftVoucher::normalise($typedCode);

        return DB::transaction(function () use ($code, $booking): GiftVoucherRedemption {
            // The lock is the whole feature: two tabs, two bookings, one
            // voucher — the second one reads the balance the first left.
            /** @var GiftVoucher|null $voucher */
            $voucher = GiftVoucher::query()->where('code', $code)->lockForUpdate()->first();

            if ($voucher === null) {
                throw new VoucherException('vouchers.error_unknown');
            }

            $error = match (true) {
                $voucher->status === GiftVoucher::PENDING => 'vouchers.error_unpaid',
                $voucher->status === GiftVoucher::VOID => 'vouchers.error_void',
                $voucher->isExpired() => 'vouchers.error_expired',
                $voucher->balance <= 0 => 'vouchers.error_empty',
                $voucher->currency !== $booking->currency => 'vouchers.error_currency',
                default => null,
            };

            if ($error !== null) {
                throw new VoucherException($error);
            }

            /** @var Booking $fresh */
            $fresh = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $amount = min($voucher->balance, $fresh->balance_due);

            if ($amount <= 0) {
                throw new VoucherException('vouchers.error_nothing_due');
            }

            $count = GiftVoucherRedemption::query()->where('gift_voucher_id', $voucher->id)->count();

            $payment = $this->payments->recordExternal(
                $fresh, 'voucher', $amount,
                "doba-voucher-{$voucher->id}-{$fresh->id}-".($count + 1),
                ['voucher' => $voucher->code],
            );

            $left = $voucher->balance - $amount;

            $voucher->forceFill([
                'balance' => $left,
                'status' => $left === 0 ? GiftVoucher::REDEEMED : GiftVoucher::ACTIVE,
            ])->save();

            return GiftVoucherRedemption::create([
                'gift_voucher_id' => $voucher->id,
                'booking_id' => $fresh->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
            ]);
        }, attempts: 3);
    }

    /**
     * A refunded voucher payment goes back on the voucher, not to a bank.
     */
    public function restore(Payment $payment, ?int $amount = null): void
    {
        DB::transaction(function () use ($payment, $amount): void {
            /** @var GiftVoucherRedemption $redemption */
            $redemption = GiftVoucherRedemption::query()->where('payment_id', $payment->id)->lockForUpdate()->firstOrFail();
            /** @var GiftVoucher $voucher */
            $voucher = GiftVoucher::query()->lockForUpdate()->findOrFail($redemption->gift_voucher_id);

            $back = min($amount ?? $redemption->amount, $redemption->amount);

            $voucher->forceFill([
                'balance' => min($voucher->initial_amount, $voucher->balance + $back),
                // A voided voucher stays void: the money is restored to the
                // record, and the hotel decides what that is worth.
                'status' => $voucher->status === GiftVoucher::REDEEMED ? GiftVoucher::ACTIVE : $voucher->status,
            ])->save();

            $redemption->forceFill(['restored_at' => CarbonImmutable::now()])->save();
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    protected function create(array $data, string $status, string $via): GiftVoucher
    {
        return GiftVoucher::create([
            'code' => GiftVoucher::newCode(),
            'initial_amount' => (int) $data['amount'],
            'balance' => (int) $data['amount'],
            'currency' => (string) config('doba.currency'),
            'status' => $status,
            'sold_via' => $via,
            'buyer_name' => (string) $data['buyer_name'],
            'buyer_email' => ($data['buyer_email'] ?? null) ?: null,
            'recipient_name' => ($data['recipient_name'] ?? null) ?: null,
            'message' => ($data['message'] ?? null) ?: null,
            'locale' => (string) ($data['locale'] ?? app()->getLocale()),
            'internal_note' => ($data['internal_note'] ?? null) ?: null,
        ]);
    }
}
