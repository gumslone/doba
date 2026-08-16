<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * The §8 gateway contract. Implementations translate one provider's API;
 * every rule that matters — webhook as source of truth, idempotency,
 * re-acquiring inventory, refund-instead-of-phantom-confirm — lives in
 * PaymentService and applies to all of them identically.
 */
interface PaymentGateway
{
    /**
     * Machine name, matching payments.gateway and PAYMENT_GATEWAY.
     */
    public function name(): string;

    /**
     * Create (or idempotently re-create) the provider object that collects
     * $amount for this booking, returning what the front end needs to
     * continue — a client secret, an approval URL, or nothing for manual.
     */
    public function createPayment(Booking $booking, int $amount, string $idempotencyKey): GatewayPayment;

    /**
     * Verify and decode an incoming webhook. MUST throw
     * InvalidWebhookException on a bad signature or a stale timestamp —
     * an unverified webhook is an unauthenticated request to confirm a
     * booking, and it gets treated exactly that seriously.
     */
    public function decodeWebhook(Request $request): WebhookEvent;

    /**
     * Refund $amount (minor units) of a captured payment. Null = full.
     */
    public function refund(Payment $payment, ?int $amount = null): void;
}
