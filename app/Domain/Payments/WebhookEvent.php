<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * A provider webhook, normalised. Anything that is not a payment outcome
 * decodes as IGNORED and is acknowledged without side effects — providers
 * send dozens of event types and new ones must never 500 the endpoint,
 * because a 500 puts the event into the provider's retry queue forever.
 */
final class WebhookEvent
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const IGNORED = 'ignored';

    /**
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $gatewayPaymentId = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly array $raw = [],
    ) {}

    public static function ignored(): self
    {
        return new self(self::IGNORED);
    }
}
