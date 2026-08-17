<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One delivery attempt (§17).
 *
 * The contract a receiver has to be told about, loudly, and which this
 * class is built around:
 *
 *  - **At least once.** A delivery that timed out after the receiver
 *    processed it will arrive again. Every payload carries an `event_id`
 *    for exactly this reason.
 *  - **Possibly out of order.** `booking.cancelled` can arrive before
 *    `booking.updated` for the same booking, because they retry on
 *    independent schedules. Every payload carries the resource's
 *    `updated_at`, and a receiver that ignores it will eventually
 *    resurrect a cancelled booking.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 1m, 5m, 30m, 2h, 6h, 24h — six attempts over a day, which covers a
     * partner's deploy window without hammering an endpoint that is down.
     *
     * @var array<int,int>
     */
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 86400];

    public int $tries = 7;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $event,
        public string $eventId,
        public array $payload,
    ) {}

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    public function handle(): void
    {
        if (! $this->endpoint->is_active) {
            return;   // switched off since this was queued
        }

        $body = (string) json_encode([
            // Carried in the body, not only in the header, so a receiver
            // that logs the payload can still dedupe from its own records.
            'event_id' => $this->eventId,
            'event' => $this->event,
            'sent_at' => CarbonImmutable::now()->toIso8601String(),
            'data' => $this->payload,
        ]);

        $timestamp = CarbonImmutable::now()->getTimestamp();

        // The timestamp is INSIDE the signed string, so a delivery
        // captured and replayed hours later fails verification even
        // though the body is byte-identical.
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->endpoint->secret);

        $started = microtime(true);
        $status = null;
        $error = null;

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Signature' => sprintf('t=%d,v1=%s', $timestamp, $signature),
                    'X-Event-Id' => $this->eventId,
                    'X-Event-Type' => $this->event,
                ])
                ->withBody($body, 'application/json')
                ->post($this->endpoint->url);

            $status = $response->status();

            if (! $response->successful()) {
                $error = 'HTTP '.$status;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $this->endpoint->id,
            'event_id' => $this->eventId,
            'event' => $this->event,
            'attempt' => $this->attempts(),
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 1000),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'created_at' => CarbonImmutable::now(),
        ]);

        if ($error === null) {
            // Reset on success, so an endpoint that fails twice a week
            // forever is never disabled — only one that is actually down.
            $this->endpoint->forceFill(['consecutive_failures' => 0])->save();

            return;
        }

        $failures = $this->endpoint->consecutive_failures + 1;

        $this->endpoint->forceFill(['consecutive_failures' => $failures])->save();

        if ($failures >= WebhookEndpoint::FAILURE_LIMIT) {
            // Switched off rather than retried forever: an endpoint that
            // has failed twenty times running is gone, and continuing to
            // queue for it fills the worker with work nobody wants.
            $this->endpoint->forceFill([
                'is_active' => false,
                'disabled_at' => CarbonImmutable::now(),
            ])->save();

            Log::error('Webhook endpoint disabled after repeated failures.', [
                'endpoint' => $this->endpoint->id,
                'url' => $this->endpoint->url,
                'failures' => $failures,
            ]);

            return;
        }

        // Thrown so the queue retries it on the backoff schedule.
        throw new \RuntimeException('Webhook delivery failed: '.$error);
    }
}
