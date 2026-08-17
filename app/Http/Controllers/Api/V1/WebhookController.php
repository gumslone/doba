<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\ApiClient;
use App\Models\WebhookEndpoint;
use App\Support\Api\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A partner managing its own subscriptions (§17).
 *
 * Scoped to the calling client: a key can only see and change the
 * endpoints it registered, so one partner cannot read another's URL or
 * quietly point their events somewhere else.
 */
class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->client($request)->webhookEndpoints()
                ->get()
                ->map(static fn (WebhookEndpoint $e): array => [
                    'id' => $e->id,
                    'url' => $e->url,
                    'events' => $e->events,
                    'active' => $e->is_active,
                    'consecutive_failures' => $e->consecutive_failures,
                ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            // https only: a webhook carries guest names and stay dates,
            // and signing a payload does not stop anyone reading it.
            'url' => ['required', 'url:https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEndpoint::EVENTS)],
        ]);

        if ($validator->fails()) {
            return Problem::validation($validator->errors()->toArray());
        }

        $secret = Str::random(48);

        $endpoint = $this->client($request)->webhookEndpoints()->create([
            'url' => $validator->validated()['url'],
            'events' => $validator->validated()['events'],
            'secret' => $secret,
        ]);

        return response()->json([
            'data' => [
                'id' => $endpoint->id,
                'url' => $endpoint->url,
                'events' => $endpoint->events,
                // Shown once. Everything after this is verified against
                // it, and nothing can read it back.
                'secret' => $secret,
            ],
        ], 201);
    }

    public function destroy(Request $request, int $webhook): JsonResponse
    {
        $endpoint = $this->client($request)->webhookEndpoints()->whereKey($webhook)->first();

        if ($endpoint === null) {
            return Problem::notFound('No such webhook endpoint.');
        }

        $endpoint->delete();

        return response()->json(null, 204);
    }

    /**
     * Send a real signed delivery, so a partner can verify their
     * signature check before a booking depends on it.
     */
    public function test(Request $request, int $webhook): JsonResponse
    {
        $endpoint = $this->client($request)->webhookEndpoints()->whereKey($webhook)->first();

        if ($endpoint === null) {
            return Problem::notFound('No such webhook endpoint.');
        }

        $eventId = (string) Str::uuid();

        DeliverWebhook::dispatch($endpoint, 'webhook.test', $eventId, [
            'message' => 'This is a test delivery from Doba.',
        ]);

        return response()->json(['event_id' => $eventId], 202);
    }

    protected function client(Request $request): ApiClient
    {
        /** @var ApiClient $client */
        $client = $request->attributes->get('api_client');

        return $client;
    }
}
