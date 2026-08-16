<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * LiqPay (PrivatBank) — the processor that matters for the Ukrainian
 * market the platform is heading to. Hosted checkout: no card data ever
 * touches this server.
 *
 * The protocol is one signed envelope everywhere: `data` is base64 JSON,
 * `signature` is base64(sha1(private_key . data . private_key)) — the same
 * scheme for the checkout link, the server callback and API calls, so
 * sign() and verify() below are the whole integration surface.
 *
 * LiqPay identifies payments by OUR order_id rather than issuing one, so
 * the payment row's gateway_payment_id is the idempotency key itself.
 */
class LiqPayGateway implements PaymentGateway
{
    protected const CHECKOUT = 'https://www.liqpay.ua/api/3/checkout';

    protected const API = 'https://www.liqpay.ua/api/request';

    public function name(): string
    {
        return 'liqpay';
    }

    public function createPayment(Booking $booking, int $amount, string $idempotencyKey): GatewayPayment
    {
        $params = [
            'version' => 3,
            'public_key' => (string) config('services.liqpay.public_key'),
            'action' => 'pay',
            'amount' => PayPalGateway::toDecimal($amount),   // decimal at the edge, like PayPal
            'currency' => $booking->currency,
            'description' => 'Booking '.$booking->reference,
            'order_id' => $idempotencyKey,
            'server_url' => url('/webhooks/liqpay'),
            'result_url' => url('/'),
            'language' => in_array($booking->locale, ['uk', 'en'], true) ? $booking->locale : 'en',
        ];

        $data = base64_encode((string) json_encode($params));

        return new GatewayPayment(
            gatewayPaymentId: $idempotencyKey,
            approvalUrl: self::CHECKOUT.'?data='.urlencode($data).'&signature='.urlencode($this->sign($data)),
            raw: $params,
        );
    }

    public function decodeWebhook(Request $request): WebhookEvent
    {
        // Fail closed on an unset key: an empty private key makes the
        // signature attacker-computable (see StripeGateway for why).
        if ((string) config('services.liqpay.private_key') === '') {
            throw new InvalidWebhookException('LiqPay private key is not configured.');
        }

        $data = (string) $request->input('data');
        $signature = (string) $request->input('signature');

        if ($data === '' || ! hash_equals($this->sign($data), $signature)) {
            throw new InvalidWebhookException('LiqPay callback signature mismatch.');
        }

        $decoded = json_decode(base64_decode($data), true);

        if (! is_array($decoded)) {
            throw new InvalidWebhookException('Malformed LiqPay callback data.');
        }

        return match ($decoded['status'] ?? '') {
            'success' => new WebhookEvent(
                WebhookEvent::SUCCEEDED,
                gatewayPaymentId: (string) ($decoded['order_id'] ?? ''),
                amount: PayPalGateway::toMinor((string) ($decoded['amount'] ?? '0')),
                currency: (string) ($decoded['currency'] ?? ''),
                raw: $decoded,
            ),
            'failure', 'error' => new WebhookEvent(
                WebhookEvent::FAILED,
                gatewayPaymentId: (string) ($decoded['order_id'] ?? ''),
                raw: $decoded,
            ),
            // wait_accept, sandbox, processing, 3ds_verify … — not final.
            default => WebhookEvent::ignored(),
        };
    }

    public function refund(Payment $payment, ?int $amount = null): void
    {
        if ($payment->gateway_payment_id === null) {
            throw new RuntimeException('Payment has no LiqPay order id to refund.');
        }

        $params = [
            'version' => 3,
            'public_key' => (string) config('services.liqpay.public_key'),
            'action' => 'refund',
            'order_id' => $payment->gateway_payment_id,
            'amount' => PayPalGateway::toDecimal($amount ?? $payment->amount),
        ];

        $data = base64_encode((string) json_encode($params));

        $response = Http::asForm()
            ->withUserAgent('Doba/1.0')
            ->post(self::API, ['data' => $data, 'signature' => $this->sign($data)])
            ->throw()
            ->json();

        if (! in_array($response['status'] ?? '', ['success', 'reversed', 'refund_wait'], true)) {
            throw new RuntimeException('LiqPay refund rejected: '.json_encode($response));
        }
    }

    protected function sign(string $data): string
    {
        $key = (string) config('services.liqpay.private_key');

        return base64_encode(sha1($key.$data.$key, true));
    }
}
