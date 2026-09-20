<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Vouchers\VoucherService;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Not a way to START a payment — a voucher is redeemed by its code
 * through VoucherService — but the thing PaymentService::refund() talks
 * to when a voucher payment is given back: the money returns to the
 * voucher's balance, not to a bank.
 */
class VoucherGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'voucher';
    }

    public function createPayment(Booking $booking, int $amount, string $idempotencyKey): GatewayPayment
    {
        throw new RuntimeException('A gift voucher is redeemed by its code, not initiated as a payment.');
    }

    public function decodeWebhook(Request $request): WebhookEvent
    {
        throw new InvalidWebhookException('Gift vouchers have no webhooks.');
    }

    public function refund(Payment $payment, ?int $amount = null): void
    {
        app(VoucherService::class)->restore($payment, $amount);
    }
}
