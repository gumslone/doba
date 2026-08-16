<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use InvalidArgumentException;

/**
 * PAYMENT_GATEWAY name → implementation. The checkout resolves the
 * configured default; webhooks always resolve their own concrete gateway
 * by URL, because a hotel that switched providers still receives events
 * for payments made under the old one.
 */
final class GatewayRegistry
{
    public const GATEWAYS = [
        'stripe' => StripeGateway::class,
        'paypal' => PayPalGateway::class,
        'liqpay' => LiqPayGateway::class,
        'coinbase' => CoinbaseGateway::class,
        'manual' => ManualGateway::class,
    ];

    public static function make(string $name): PaymentGateway
    {
        $class = self::GATEWAYS[$name]
            ?? throw new InvalidArgumentException("Unknown payment gateway [{$name}].");

        return app($class);
    }

    public static function default(): PaymentGateway
    {
        return self::make((string) config('doba.payment.gateway', 'manual'));
    }
}
