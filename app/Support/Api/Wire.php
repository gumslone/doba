<?php

declare(strict_types=1);

namespace App\Support\Api;

/**
 * How values look on the wire (§17).
 */
final class Wire
{
    /**
     * Money: integer minor units plus an ISO code, never a decimal string.
     *
     * `{"amount": 12500, "currency": "EUR"}` removes an entire category of
     * integration bug — the one where a partner parses "125.00" as a
     * float, multiplies it by three nights and books a stay for
     * 374.99999999.
     *
     * @return array{amount:int,currency:string}
     */
    public static function money(?int $minor): array
    {
        return [
            'amount' => (int) $minor,
            'currency' => (string) config('doba.currency', 'EUR'),
        ];
    }
}
