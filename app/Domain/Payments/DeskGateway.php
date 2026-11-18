<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Money that changed hands at the desk: cash, the card terminal, a bank
 * transfer the bookkeeper saw arrive (§8).
 *
 * Not a checkout — a hotel cannot choose it as its gateway. It exists so
 * that a payment the desk recorded sits in the same ledger as one Stripe
 * took, and is refunded through the same one path. There is nothing to
 * call: the refund is the clerk handing the money back, and this records
 * that they did.
 */
class DeskGateway implements PaymentGateway
{
    /** How the money arrived. Free text would make the report useless. */
    public const METHODS = ['cash', 'card', 'transfer', 'other'];

    public function name(): string
    {
        return 'desk';
    }

    public function createPayment(Booking $booking, int $amount, string $idempotencyKey): GatewayPayment
    {
        throw new RuntimeException('A desk payment is recorded after the fact, not initiated.');
    }

    public function decodeWebhook(Request $request): WebhookEvent
    {
        throw new InvalidWebhookException('Desk payments have no webhooks.');
    }

    public function refund(Payment $payment, ?int $amount = null): void
    {
        // Handed back over the counter. The ledger entry is the record.
    }
}
