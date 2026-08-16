<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * What createPayment() hands back to the checkout front end.
 */
final class GatewayPayment
{
    /**
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public readonly ?string $gatewayPaymentId,
        // Stripe: the PaymentIntent client secret for Elements.
        public readonly ?string $clientSecret = null,
        // PayPal: where to send the guest to approve the order.
        public readonly ?string $approvalUrl = null,
        // Manual: nothing to do online; the booking confirms directly.
        public readonly bool $confirmImmediately = false,
        public readonly array $raw = [],
    ) {}
}
