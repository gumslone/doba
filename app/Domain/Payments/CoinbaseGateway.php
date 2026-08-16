<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Crypto via Coinbase Commerce hosted checkout: the guest pays in
 * BTC/ETH/USDC/…, the charge is priced in the hotel's fiat currency, and
 * no wallet code lives in this codebase.
 *
 * The honest caveat, encoded in refund(): crypto settlements have NO
 * refund API. The late-webhook safety net that auto-refunds a resold room
 * (§6/§8) degrades to "cancel + flag for a manual refund" here —
 * PaymentService knows and handles that fallback explicitly.
 */
class CoinbaseGateway implements PaymentGateway
{
    protected const BASE = 'https://api.commerce.coinbase.com';

    public function name(): string
    {
        return 'coinbase';
    }

    public function createPayment(Booking $booking, int $amount, string $idempotencyKey): GatewayPayment
    {
        $response = Http::withHeaders([
            'X-CC-Api-Key' => (string) config('services.coinbase.api_key'),
            'X-CC-Version' => '2018-03-22',
        ])
            ->withUserAgent('Doba/1.0')
            ->post(self::BASE.'/charges', [
                'name' => 'Booking '.$booking->reference,
                'description' => 'Deposit for booking '.$booking->reference,
                'pricing_type' => 'fixed_price',
                'local_price' => [
                    'amount' => PayPalGateway::toDecimal($amount),
                    'currency' => $booking->currency,
                ],
                'metadata' => ['booking_reference' => $booking->reference],
            ])
            ->throw()
            ->json('data');

        return new GatewayPayment(
            gatewayPaymentId: (string) $response['id'],
            approvalUrl: $response['hosted_url'] ?? null,
            raw: $response,
        );
    }

    public function decodeWebhook(Request $request): WebhookEvent
    {
        $payload = $request->getContent();

        $secret = (string) config('services.coinbase.webhook_secret');

        // Fail closed on an unset secret (see StripeGateway for why).
        if ($secret === '') {
            throw new InvalidWebhookException('Coinbase Commerce webhook secret is not configured.');
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expected, (string) $request->header('X-CC-Webhook-Signature'))) {
            throw new InvalidWebhookException('Coinbase Commerce webhook signature mismatch.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new InvalidWebhookException('Malformed Coinbase Commerce payload.');
        }

        $charge = $event['event']['data'] ?? [];

        return match ($event['event']['type'] ?? '') {
            // 'confirmed' = on-chain confirmation reached; 'resolved' covers
            // a charge manually resolved after over/underpayment.
            'charge:confirmed', 'charge:resolved' => new WebhookEvent(
                WebhookEvent::SUCCEEDED,
                gatewayPaymentId: (string) ($charge['id'] ?? ''),
                amount: PayPalGateway::toMinor((string) ($charge['pricing']['local']['amount'] ?? '0')),
                currency: (string) ($charge['pricing']['local']['currency'] ?? ''),
                raw: $event,
            ),
            'charge:failed' => new WebhookEvent(
                WebhookEvent::FAILED,
                gatewayPaymentId: (string) ($charge['id'] ?? ''),
                raw: $event,
            ),
            default => WebhookEvent::ignored(),
        };
    }

    public function refund(Payment $payment, ?int $amount = null): void
    {
        // There is deliberately no partial pretence here: throwing is what
        // routes PaymentService into its manual-refund fallback.
        throw new RuntimeException(
            'Crypto payments cannot be refunded via API; refund manually from the Coinbase Commerce dashboard.'
        );
    }
}
