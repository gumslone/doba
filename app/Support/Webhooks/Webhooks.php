<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Fan an event out to everybody who asked for it (§17).
 */
class Webhooks
{
    /**
     * @param  array<string,mixed>  $payload
     * @return int how many endpoints it went to
     */
    public function emit(string $event, array $payload): int
    {
        $endpoints = WebhookEndpoint::query()
            ->where('is_active', true)
            ->get()
            ->filter(static fn (WebhookEndpoint $endpoint): bool => $endpoint->wants($event));

        // One id for the event, shared by every endpoint's delivery of
        // it: a partner asking "did you send X" and a hotelier grepping
        // the log are talking about the same thing.
        $eventId = (string) Str::uuid();

        foreach ($endpoints as $endpoint) {
            DeliverWebhook::dispatch($endpoint, $event, $eventId, $payload);
        }

        return $endpoints->count();
    }
}
