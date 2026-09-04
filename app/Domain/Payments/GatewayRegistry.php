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

    /**
     * The gateway the checkout initiates with.
     *
     * FEATURE_PAYMENT off means "take bookings without online payment
     * for now" (§16 step 6) — a legitimate starting configuration, and
     * one the flag had promised since phase 1 without ever being read.
     * It resolves to the manual gateway whatever DOBA_PAYMENT_GATEWAY
     * says, so a hotel can keep its Stripe keys configured and still
     * switch collection off for a season.
     */
    public static function default(): PaymentGateway
    {
        if (! (bool) config('doba.features.online_payment', true)) {
            return self::make('manual');
        }

        return self::make((string) config('doba.payment.gateway', 'manual'));
    }
}
