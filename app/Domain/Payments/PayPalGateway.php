<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayPal Orders v2 over plain HTTP. Two impedance mismatches handled at
 * this edge and nowhere else:
 *
 *  - PayPal speaks decimal strings ("125.00"); the whole system speaks
 *    integer minor units (§5). Conversion happens here only.
 *  - Webhook authenticity cannot be checked locally: PayPal's scheme
 *    requires calling verify-webhook-signature. That call IS the
 *    verification — skipping it accepts anonymous booking confirmations.
 */
class PayPalGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'paypal';
    }

    public function createPayment(Booking $booking, int $amount, string $idempotencyKey): GatewayPayment
    {
        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey])
            ->post($this->base().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $booking->reference,
                    'custom_id' => $booking->reference,
                    'amount' => [
                        'currency_code' => $booking->currency,
                        'value' => self::toDecimal($amount),
                    ],
                ]],
            ])
            ->throw()
            ->json();

        $approval = null;

        foreach ((array) ($response['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'approve') {
                $approval = $link['href'] ?? null;

                break;
            }
        }

        return new GatewayPayment(
            gatewayPaymentId: (string) $response['id'],
            approvalUrl: $approval,
            raw: $response,
        );
    }

    public function decodeWebhook(Request $request): WebhookEvent
    {
        $event = $request->json()->all();

        $this->verifyWithPayPal($request, $event);

        $resource = $event['resource'] ?? [];

        return match ($event['event_type'] ?? '') {
            'PAYMENT.CAPTURE.COMPLETED' => new WebhookEvent(
                WebhookEvent::SUCCEEDED,
                // supplementary_data carries the order id the payment row
                // was keyed on at creation; the capture id differs from it.
                gatewayPaymentId: (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? ''),
                amount: self::toMinor($resource['amount']['value'] ?? '0'),
                currency: (string) ($resource['amount']['currency_code'] ?? ''),
                raw: $event,
            ),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => new WebhookEvent(
                WebhookEvent::FAILED,
                gatewayPaymentId: (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? ''),
                raw: $event,
            ),
            default => WebhookEvent::ignored(),
        };
    }

    public function refund(Payment $payment, ?int $amount = null): void
    {
        $captureId = $payment->payload['capture_id']
            ?? $payment->payload['resource']['id']
            ?? null;

        if ($captureId === null) {
            throw new RuntimeException('Payment has no PayPal capture id to refund.');
        }

        $body = $amount === null ? [] : [
            'amount' => [
                'value' => self::toDecimal($amount),
                'currency_code' => $payment->currency,
            ],
        ];

        $this->client()
            ->post($this->base()."/v2/payments/captures/{$captureId}/refund", $body)
            ->throw();
    }

    /**
     * @param  array<string,mixed>  $event
     */
    protected function verifyWithPayPal(Request $request, array $event): void
    {
        $response = $this->client()->post($this->base().'/v1/notifications/verify-webhook-signature', [
            'auth_algo' => (string) $request->header('Paypal-Auth-Algo'),
            'cert_url' => (string) $request->header('Paypal-Cert-Url'),
            'transmission_id' => (string) $request->header('Paypal-Transmission-Id'),
            'transmission_sig' => (string) $request->header('Paypal-Transmission-Sig'),
            'transmission_time' => (string) $request->header('Paypal-Transmission-Time'),
            'webhook_id' => (string) config('services.paypal.webhook_id'),
            'webhook_event' => $event,
        ]);

        if (! $response->successful()
            || $response->json('verification_status') !== 'SUCCESS') {
            throw new InvalidWebhookException('PayPal webhook verification failed.');
        }
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->accessToken())->withUserAgent('Doba/1.0');
    }

    protected function accessToken(): string
    {
        return (string) Cache::remember('doba:paypal:token', now()->addHours(7), function (): string {
            $response = Http::asForm()
                ->withBasicAuth(
                    (string) config('services.paypal.client_id'),
                    (string) config('services.paypal.secret')
                )
                ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
                ->throw();

            return (string) $response->json('access_token');
        });
    }

    protected function base(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public static function toDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    public static function toMinor(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
