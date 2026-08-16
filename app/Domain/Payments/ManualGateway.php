<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Bank transfer / pay on arrival (§8): no provider, no webhook. The
 * booking confirms immediately — the hotel has chosen to trust the guest —
 * and the payment row stays pending until staff mark it settled.
 */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function createPayment(Booking $booking, int $amount, string $idempotencyKey): GatewayPayment
    {
        return new GatewayPayment(
            gatewayPaymentId: null,
            confirmImmediately: true,
        );
    }

    public function decodeWebhook(Request $request): WebhookEvent
    {
        throw new InvalidWebhookException('The manual gateway has no webhooks.');
    }

    public function refund(Payment $payment, ?int $amount = null): void
    {
        throw new RuntimeException(
            'Manual payments are refunded outside the system; record it in the admin panel.'
        );
    }
}
