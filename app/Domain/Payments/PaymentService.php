<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Booking\BookingService;
use App\Domain\Booking\NoAvailabilityException;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The §8 orchestration. Every rule that keeps money and inventory honest
 * lives here, once, for all gateways:
 *
 *  - the WEBHOOK is the source of truth — the browser redirect never
 *    confirms anything
 *  - handlers are idempotent on the gateway payment id; provider retries
 *    (Stripe retries for days) are free
 *  - confirmation re-acquires inventory via BookingService; when the room
 *    is genuinely gone, the guest is refunded automatically — money taken
 *    with no room is recoverable, an overbooked guest at 11pm is not
 */
class PaymentService
{
    public function __construct(protected BookingService $bookings) {}

    /**
     * Start collecting the deposit for a pending booking. The §6 rule:
     * this is the moment the hold clock matters, so callers create the
     * intent as the guest reaches payment, not when the summary renders.
     */
    public function initiate(PaymentGateway $gateway, Booking $booking): Payment
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw new InvalidArgumentException(
                "Booking {$booking->reference} is {$booking->status->value}; only pending bookings take a deposit."
            );
        }

        $amount = $booking->deposit_due;

        // Derived from the booking (§8): the same booking re-initiating
        // reuses one idempotency key end to end, so neither our payments
        // table nor the provider can double-charge.
        $idempotencyKey = "doba-{$booking->reference}-deposit";

        $gatewayPayment = $gateway->createPayment($booking, $amount, $idempotencyKey);

        $payment = Payment::query()->updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'booking_id' => $booking->id,
                'gateway' => $gateway->name(),
                'gateway_payment_id' => $gatewayPayment->gatewayPaymentId,
                'type' => 'deposit',
                'status' => PaymentStatus::Pending,
                'amount' => $amount,
                'currency' => $booking->currency,
                'payload' => $gatewayPayment->raw,
            ]
        );

        if ($gatewayPayment->confirmImmediately) {
            $this->bookings->transition($booking, BookingStatus::Confirmed, 'Manual payment on arrival/transfer');
        }

        return $payment;
    }

    /**
     * The webhook handler behind /webhooks/{gateway}. Signature has
     * already been verified by decodeWebhook, or we would not be here.
     */
    public function handleWebhook(PaymentGateway $gateway, WebhookEvent $event): void
    {
        if ($event->type === WebhookEvent::IGNORED) {
            return;
        }

        $payment = Payment::query()
            ->where('gateway', $gateway->name())
            ->where('gateway_payment_id', $event->gatewayPaymentId)
            ->first();

        if ($payment === null) {
            // A verified event for an object we never created — most likely
            // another system on the same account. Log and acknowledge;
            // erroring would only park it in the provider's retry queue.
            Log::warning('Webhook for unknown payment object.', [
                'gateway' => $gateway->name(),
                'gateway_payment_id' => $event->gatewayPaymentId,
            ]);

            return;
        }

        match ($event->type) {
            WebhookEvent::SUCCEEDED => $this->handleSucceeded($gateway, $payment, $event),
            WebhookEvent::FAILED => $this->handleFailed($payment, $event),
            default => null,
        };
    }

    protected function handleSucceeded(PaymentGateway $gateway, Payment $payment, WebhookEvent $event): void
    {
        // Underpayment guard. The event amount is signature-protected, so
        // this is not an attacker vector, but a genuine short capture (a
        // crypto under-payment resolved as complete, a partial auth) must
        // not confirm a booking as fully paid. Credit is always the row's
        // own amount; a short event is treated as a failure to be retried
        // or handled by staff, never a silent confirmation.
        if ($event->amount !== null && $event->amount < $payment->amount) {
            $this->handleFailed($payment, $event);

            Log::warning('Payment webhook amount below the expected deposit; not confirming.', [
                'payment' => $payment->id,
                'expected' => $payment->amount,
                'received' => $event->amount,
            ]);

            return;
        }

        // Idempotency on the gateway payment id (§8): a redelivered
        // success event finds the row already paid and does nothing.
        $fresh = DB::transaction(function () use ($payment, $event): ?Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
                return null;
            }

            $locked->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'payload' => array_merge($locked->payload ?? [], ['webhook' => $event->raw]),
            ]);

            $locked->booking->increment('paid_amount', $locked->amount);
            $locked->booking->decrement('balance_due', $locked->amount);

            return $locked;
        });

        if ($fresh === null) {
            return; // duplicate delivery
        }

        $booking = $fresh->booking()->first();

        if ($booking->status !== BookingStatus::Pending) {
            return; // already confirmed by an earlier delivery or by staff
        }

        try {
            // Re-acquires inventory under lock — never trusts that the
            // hold survived the 3-D Secure round trip (§6, §8).
            $this->bookings->transition($booking, BookingStatus::Confirmed, 'Payment received');
        } catch (NoAvailabilityException) {
            // Money taken, room gone: never confirm — that would
            // manufacture the overbooking this architecture exists to
            // prevent. Refund in full and release the booking.
            try {
                $gateway->refund($fresh);

                $fresh->update([
                    'status' => PaymentStatus::Refunded,
                    'refunded_at' => now(),
                ]);

                $booking->decrement('paid_amount', $fresh->amount);
                $booking->increment('balance_due', $fresh->amount);

                $reason = 'Payment arrived after the room was resold; automatically refunded';

                Log::critical('Late payment refunded — room resold before webhook.', [
                    'booking' => $booking->reference,
                    'payment' => $fresh->id,
                ]);
            } catch (\Throwable $refundError) {
                // Some rails cannot refund via API (crypto). The booking is
                // still released — a phantom confirmation is the one
                // unacceptable outcome — and the money is flagged for a
                // human, loudly. Payment stays 'paid' so the liability is
                // visible until staff settle it.
                $reason = 'Payment arrived after the room was resold; MANUAL REFUND REQUIRED';

                Log::critical('Late payment needs a MANUAL refund — gateway cannot refund via API.', [
                    'booking' => $booking->reference,
                    'payment' => $fresh->id,
                    'gateway' => $gateway->name(),
                    'error' => $refundError->getMessage(),
                ]);
            }

            $this->bookings->transition($booking, BookingStatus::Cancelled, $reason);
        }
    }

    protected function handleFailed(Payment $payment, WebhookEvent $event): void
    {
        if ($payment->status === PaymentStatus::Paid) {
            return; // out-of-order delivery after a success — success wins
        }

        $payment->update([
            'status' => PaymentStatus::Failed,
            'payload' => array_merge($payment->payload ?? [], ['webhook' => $event->raw]),
        ]);

        // The booking stays pending: the guest may retry with another card
        // inside the hold window, and holds:release cleans up if not.
    }

    /**
     * Staff-initiated refund, partial or full (§8).
     */
    public function refund(PaymentGateway $gateway, Payment $payment, ?int $amount = null): Payment
    {
        if ($payment->status !== PaymentStatus::Paid && $payment->status !== PaymentStatus::PartiallyRefunded) {
            throw new InvalidArgumentException('Only captured payments can be refunded.');
        }

        // Refund rows never carry the provider object id — the webhook
        // idempotency index (gateway, gateway_payment_id) reserves that
        // pair for the original. They link back through the key prefix.
        $alreadyRefunded = (int) Payment::query()
            ->where('type', 'refund')
            ->where('idempotency_key', 'like', "doba-refund-{$payment->id}-%")
            ->sum('amount');

        $amount ??= $payment->amount - $alreadyRefunded;

        if ($amount < 1 || $amount + $alreadyRefunded > $payment->amount) {
            throw new InvalidArgumentException('Refund exceeds the refundable remainder.');
        }

        $gateway->refund($payment, $amount);

        $refund = Payment::query()->create([
            'booking_id' => $payment->booking_id,
            'gateway' => $payment->gateway,
            'gateway_payment_id' => null,
            'type' => 'refund',
            'status' => PaymentStatus::Refunded,
            'amount' => $amount,
            'currency' => $payment->currency,
            'refunded_at' => now(),
            'idempotency_key' => "doba-refund-{$payment->id}-".($alreadyRefunded + $amount),
        ]);

        $payment->update([
            'status' => $alreadyRefunded + $amount >= $payment->amount
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded,
            'refunded_at' => now(),
        ]);

        $payment->booking->decrement('paid_amount', $amount);
        $payment->booking->increment('balance_due', $amount);

        return $refund;
    }
}
