<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe over its plain HTTP API — no SDK, so every call is fakeable with
 * Http::fake() and the dependency surface stays at zero.
 *
 * PCI scope: card data never touches this server. createPayment() returns
 * the PaymentIntent client secret and Stripe Elements collects the card in
 * the browser (SAQ-A, §8). Do not build a card form.
 */
class StripeGateway implements PaymentGateway
{
    protected const BASE = 'https://api.stripe.com/v1';

    /**
     * Signed webhooks older than this are replays (§8/§14).
     */
    protected const TOLERANCE_SECONDS = 300;

    public function name(): string
    {
        return 'stripe';
    }

    public function createPayment(Booking $booking, int $amount, string $idempotencyKey): GatewayPayment
    {
        // Stripe's own idempotency layer: a double-clicked button or a
        // retried request replays the SAME PaymentIntent instead of
        // creating a second charge (§8).
        $response = $this->client()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->asForm()
            ->post(self::BASE.'/payment_intents', [
                'amount' => $amount,                       // Stripe speaks minor units natively
                'currency' => strtolower($booking->currency),
                'automatic_payment_methods[enabled]' => 'true',
                'metadata[booking_reference]' => $booking->reference,
                'description' => 'Booking '.$booking->reference,
            ])
            ->throw()
            ->json();

        return new GatewayPayment(
            gatewayPaymentId: (string) $response['id'],
            clientSecret: $response['client_secret'] ?? null,
            raw: $response,
        );
    }

    public function decodeWebhook(Request $request): WebhookEvent
    {
        $payload = $request->getContent();

        $this->verifySignature($payload, (string) $request->header('Stripe-Signature'));

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new InvalidWebhookException('Malformed Stripe payload.');
        }

        $object = $event['data']['object'] ?? [];

        return match ($event['type'] ?? '') {
            'payment_intent.succeeded' => new WebhookEvent(
                WebhookEvent::SUCCEEDED,
                gatewayPaymentId: (string) ($object['id'] ?? ''),
                amount: (int) ($object['amount_received'] ?? $object['amount'] ?? 0),
                currency: strtoupper((string) ($object['currency'] ?? '')),
                raw: $event,
            ),
            'payment_intent.payment_failed' => new WebhookEvent(
                WebhookEvent::FAILED,
                gatewayPaymentId: (string) ($object['id'] ?? ''),
                raw: $event,
            ),
            default => WebhookEvent::ignored(),
        };
    }

    public function refund(Payment $payment, ?int $amount = null): void
    {
        if ($payment->gateway_payment_id === null) {
            throw new RuntimeException('Payment has no Stripe id to refund.');
        }

        $this->client()
            ->asForm()
            ->post(self::BASE.'/refunds', array_filter([
                'payment_intent' => $payment->gateway_payment_id,
                'amount' => $amount,
            ], static fn ($value) => $value !== null))
            ->throw();
    }

    /**
     * Stripe's t=<ts>,v1=<hmac> scheme: HMAC-SHA256 of "<ts>.<payload>"
     * with the endpoint secret, constant-time compared, timestamp bounded.
     */
    protected function verifySignature(string $payload, string $header): void
    {
        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key][] = $value;
        }

        $secret = (string) config('services.stripe.webhook_secret');

        // Fail closed on an unset secret. Without this an empty key makes the
        // HMAC attacker-computable, so a forged webhook could confirm a
        // booking — the endpoint would authenticate nobody while looking
        // like it authenticates Stripe.
        if ($secret === '') {
            throw new InvalidWebhookException('Stripe webhook secret is not configured.');
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);

        if (abs(now()->timestamp - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new InvalidWebhookException('Stripe webhook timestamp outside tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new InvalidWebhookException('Stripe webhook signature mismatch.');
    }

    protected function client(): PendingRequest
    {
        return Http::withToken((string) config('services.stripe.secret'))
            ->withUserAgent('Doba/1.0');
    }
}
