<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payments\CoinbaseGateway;
use App\Domain\Payments\InvalidWebhookException;
use App\Domain\Payments\LiqPayGateway;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\PayPalGateway;
use App\Domain\Payments\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Thin translation layer: verify → normalise → hand to PaymentService.
 *
 * Resolved per URL, not from PAYMENT_GATEWAY: a hotel that switched
 * providers still receives (and must still verify) events for payments
 * made under the old one.
 */
class PaymentWebhookController extends Controller
{
    public function stripe(Request $request, StripeGateway $gateway, PaymentService $payments): Response
    {
        return $this->handle($request, $gateway, $payments);
    }

    public function paypal(Request $request, PayPalGateway $gateway, PaymentService $payments): Response
    {
        return $this->handle($request, $gateway, $payments);
    }

    public function liqpay(Request $request, LiqPayGateway $gateway, PaymentService $payments): Response
    {
        return $this->handle($request, $gateway, $payments);
    }

    public function coinbase(Request $request, CoinbaseGateway $gateway, PaymentService $payments): Response
    {
        return $this->handle($request, $gateway, $payments);
    }

    protected function handle(Request $request, PaymentGateway $gateway, PaymentService $payments): Response
    {
        try {
            $event = $gateway->decodeWebhook($request);
        } catch (InvalidWebhookException $e) {
            // 400, not 500: the provider must not retry a request that can
            // never verify, and a scanner learns nothing but "bad request".
            return response('Invalid webhook.', 400);
        }

        $payments->handleWebhook($gateway, $event);

        return response('', 200);
    }
}
