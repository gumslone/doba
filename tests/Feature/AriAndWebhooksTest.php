<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Jobs\DeliverWebhook;
use App\Models\ApiClient;
use App\Models\Availability;
use App\Models\RoomType;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 10000, 'total_units' => 3,
    ]);
    $this->roomType->translations()->create(['locale' => 'en', 'slug' => 'double', 'name' => 'Double']);

    $this->start = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 20) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->start->addDays($i)->toDateString(),
            'allotment' => 3,
        ]);
    }

    ['client' => $this->client, 'secret' => $this->secret] = ApiClient::issue('Channel manager', ApiClient::SCOPES);

    $this->auth = fn (array $extra = []): array => array_merge([
        'X-Api-Key-Id' => $this->client->key_id,
        'X-Api-Secret' => $this->secret,
    ], $extra);

    $this->night = fn (int $offset) => Availability::query()
        ->where('room_type_id', $this->roomType->id)
        ->where('date', $this->start->addDays($offset)->toDateString())
        ->first();
});

it('pushes availability across a range', function (): void {
    $body = ['updates' => [[
        'room_type' => 'DBL',
        'from' => $this->start->toDateString(),
        'to' => $this->start->addDays(4)->toDateString(),
        'allotment' => 2,
        'min_stay' => 3,
    ]]];

    $this->putJson('/api/v1/availability', $body, ($this->auth)())
        ->assertOk()
        ->assertJsonPath('nights_updated', 5)
        ->assertJsonPath('refused', []);

    expect(($this->night)(0))->allotment->toBe(2)->min_stay->toBe(3)
        ->and(($this->night)(5)->allotment)->toBe(3);
});

it('is a range write, not an increment', function (): void {
    $body = ['updates' => [[
        'room_type' => 'DBL',
        'from' => $this->start->toDateString(),
        'to' => $this->start->addDays(2)->toDateString(),
        'allotment' => 1,
    ]]];

    $this->putJson('/api/v1/availability', $body, ($this->auth)())->assertOk();
    $this->putJson('/api/v1/availability', $body, ($this->auth)())->assertOk();
    $this->putJson('/api/v1/availability', $body, ($this->auth)())->assertOk();

    // A channel manager whose push timed out sends the identical body
    // again. Three identical pushes leave the allotment where one did.
    expect(($this->night)(0)->allotment)->toBe(1);
});

it('applies a weekday mask, so one call is not thirty-one', function (): void {
    // Saturdays only. Monday-first ISO, so the bit is 1 << (isoWeekday-1)
    // — Saturday is isoWeekday 6, which is 32. (64 is Sunday, and getting
    // this backwards is how a hotel prices the wrong day of the week.)
    $saturday = $this->start->next(CarbonImmutable::SATURDAY);

    $this->putJson('/api/v1/availability', ['updates' => [[
        'room_type' => 'DBL',
        'from' => $this->start->toDateString(),
        'to' => $this->start->addDays(20)->toDateString(),
        'weekdays' => 32,
        'min_stay' => 2,
    ]]], ($this->auth)())->assertOk();

    $saturdayRow = Availability::query()->where('date', $saturday->toDateString())->first();
    $sundayRow = Availability::query()->where('date', $saturday->addDay()->toDateString())->first();

    expect($saturdayRow->min_stay)->toBe(2)
        ->and($sundayRow->min_stay)->toBe(1);
});

it('refuses to push an allotment below what is already sold, and names the night', function (): void {
    $service = app(BookingService::class);

    $booking = $service->place(
        $this->roomType, $this->start, $this->start->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2, units: 2,
    );
    $service->transition($booking, BookingStatus::Confirmed);

    $response = $this->putJson('/api/v1/availability', ['updates' => [[
        'room_type' => 'DBL',
        'from' => $this->start->toDateString(),
        'to' => $this->start->addDays(4)->toDateString(),
        'allotment' => 1,
    ]]], ($this->auth)())->assertOk();

    // 200 with the refusals listed, not a blanket rejection: a six-month
    // push should not be thrown away over one oversold night, and the
    // partner needs to know which night.
    expect($response->json('refused'))->toHaveCount(2)
        ->and($response->json('refused.0.date'))->toBe($this->start->toDateString())
        ->and($response->json('nights_updated'))->toBe(3);

    // And the sold nights kept their real allotment.
    expect(($this->night)(0)->allotment)->toBe(3);
});

it('pushes rates in minor units', function (): void {
    $this->putJson('/api/v1/rates', ['updates' => [[
        'room_type' => 'DBL',
        'from' => $this->start->toDateString(),
        'to' => $this->start->addDays(2)->toDateString(),
        'price' => 14500,
    ]]], ($this->auth)())->assertOk()->assertJsonPath('nights_updated', 3);

    expect(($this->night)(0)->price)->toBe(14500);
});

it('says why a per-plan rate cannot be pushed rather than ignoring it', function (): void {
    $response = $this->putJson('/api/v1/rates', ['updates' => [[
        'room_type' => 'DBL',
        'from' => $this->start->toDateString(),
        'to' => $this->start->toDateString(),
        'price' => 10000,
        'rate_plan' => 'SAVER',
    ]]], ($this->auth)())->assertStatus(422);

    // Silently ignoring it would cost a partner a week wondering why
    // their push had no effect.
    expect($response->json('type'))->toBe('https://docs.doba.dev/problems/rate-plan-not-pushable');
});

it('needs a write scope to push anything', function (): void {
    ['client' => $readOnly, 'secret' => $secret] = ApiClient::issue('Reader', ['availability:read', 'rates:read']);

    $headers = ['X-Api-Key-Id' => $readOnly->key_id, 'X-Api-Secret' => $secret];

    $this->putJson('/api/v1/availability', ['updates' => [[
        'room_type' => 'DBL', 'from' => $this->start->toDateString(),
        'to' => $this->start->toDateString(), 'allotment' => 1,
    ]]], $headers)->assertStatus(403);

    $this->putJson('/api/v1/rates', ['updates' => [[
        'room_type' => 'DBL', 'from' => $this->start->toDateString(),
        'to' => $this->start->toDateString(), 'price' => 100,
    ]]], $headers)->assertStatus(403);
});

it('tells subscribers when a booking is made and cancelled', function (): void {
    Queue::fake();

    $this->client->webhookEndpoints()->create([
        'url' => 'https://partner.example/hooks',
        'secret' => 'sh',
        'events' => ['booking.created', 'booking.cancelled'],
    ]);

    $service = app(BookingService::class);
    $booking = $service->place(
        $this->roomType, $this->start, $this->start->addDay(),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2,
    );

    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->event === 'booking.created');

    $service->transition($booking, BookingStatus::Cancelled);

    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->event === 'booking.cancelled');
});

it('sends only the events an endpoint asked for', function (): void {
    Queue::fake();

    $this->client->webhookEndpoints()->create([
        'url' => 'https://partner.example/hooks',
        'secret' => 'sh',
        'events' => ['payment.succeeded'],
    ]);

    app(BookingService::class)->place(
        $this->roomType, $this->start, $this->start->addDay(),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2,
    );

    // A new event type must never arrive unannounced at a receiver that
    // will throw on it.
    Queue::assertNotPushed(DeliverWebhook::class);
});

it('signs a delivery with the timestamp inside the signature', function (): void {
    Http::fake(['partner.example/*' => Http::response('', 200)]);

    $endpoint = $this->client->webhookEndpoints()->create([
        'url' => 'https://partner.example/hooks',
        'secret' => 'shared-secret',
        'events' => ['booking.created'],
    ]);

    (new DeliverWebhook($endpoint, 'booking.created', 'evt-1', ['reference' => 'HOT-1']))->handle();

    Http::assertSent(function ($request) use ($endpoint): bool {
        preg_match('/^t=(\d+),v1=([a-f0-9]+)$/', $request->header('X-Signature')[0], $m);

        // Recomputed exactly as a receiver would: the timestamp is part
        // of the signed string, so a delivery captured and replayed an
        // hour later fails verification even though the body is
        // byte-identical.
        $expected = hash_hmac('sha256', $m[1].'.'.$request->body(), $endpoint->secret);

        return hash_equals($expected, $m[2])
            && json_decode($request->body(), true)['event_id'] === 'evt-1';
    });

    expect(WebhookDelivery::query()->where('event_id', 'evt-1')->value('status'))->toBe(200);
});

it('carries updated_at, because deliveries arrive out of order', function (): void {
    Http::fake(['partner.example/*' => Http::response('', 200)]);

    $endpoint = $this->client->webhookEndpoints()->create([
        'url' => 'https://partner.example/hooks',
        'secret' => 's',
        'events' => ['booking.updated'],
    ]);

    $booking = app(BookingService::class)->place(
        $this->roomType, $this->start, $this->start->addDay(),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2,
    );

    (new DeliverWebhook($endpoint, 'booking.updated', 'evt-2', [
        'reference' => $booking->reference,
        'updated_at' => $booking->updated_at?->toIso8601String(),
    ]))->handle();

    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        // A receiver that ignores this will eventually resurrect a
        // cancelled booking.
        return isset($body['data']['updated_at'], $body['event_id']);
    });
});

it('records every attempt and disables an endpoint that stays down', function (): void {
    Http::fake(['partner.example/*' => Http::response('', 500)]);

    $endpoint = $this->client->webhookEndpoints()->create([
        'url' => 'https://partner.example/hooks',
        'secret' => 's',
        'events' => ['booking.created'],
        'consecutive_failures' => WebhookEndpoint::FAILURE_LIMIT - 1,
    ]);

    (new DeliverWebhook($endpoint, 'booking.created', 'evt-3', []))->handle();

    $endpoint->refresh();

    // Twenty failures running means the endpoint is gone; continuing to
    // queue for it fills the worker with work nobody wants.
    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_at)->not->toBeNull()
        ->and(WebhookDelivery::query()->where('event_id', 'evt-3')->value('error'))->toBe('HTTP 500');
});

it('resets the failure count on a success', function (): void {
    Http::fake(['partner.example/*' => Http::response('', 200)]);

    $endpoint = $this->client->webhookEndpoints()->create([
        'url' => 'https://partner.example/hooks',
        'secret' => 's',
        'events' => ['booking.created'],
        'consecutive_failures' => 5,
    ]);

    (new DeliverWebhook($endpoint, 'booking.created', 'evt-4', []))->handle();

    // An endpoint that fails twice a week forever is never disabled —
    // only one that is actually down.
    expect($endpoint->fresh()->consecutive_failures)->toBe(0);
});

it('lets a partner manage only its own subscriptions', function (): void {
    $response = $this->postJson('/api/v1/webhooks', [
        'url' => 'https://partner.example/hooks',
        'events' => ['booking.created'],
    ], ($this->auth)())->assertStatus(201);

    // Shown once, and never readable again.
    expect($response->json('data.secret'))->toBeString();

    $id = $response->json('data.id');

    expect($this->getJson('/api/v1/webhooks', ($this->auth)())->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/webhooks', ($this->auth)())->json('data.0'))->not->toHaveKey('secret');

    ['client' => $other, 'secret' => $otherSecret] = ApiClient::issue('Somebody else', ApiClient::SCOPES);
    $otherHeaders = ['X-Api-Key-Id' => $other->key_id, 'X-Api-Secret' => $otherSecret];

    // One partner cannot read another's URL or point their events away.
    expect($this->getJson('/api/v1/webhooks', $otherHeaders)->json('data'))->toBe([]);
    $this->deleteJson('/api/v1/webhooks/'.$id, [], $otherHeaders)->assertStatus(404);

    $this->deleteJson('/api/v1/webhooks/'.$id, [], ($this->auth)())->assertStatus(204);
});

it('refuses a plaintext webhook URL', function (): void {
    // A webhook carries guest names and stay dates, and signing a payload
    // does not stop anyone reading it.
    $this->postJson('/api/v1/webhooks', [
        'url' => 'http://partner.example/hooks',
        'events' => ['booking.created'],
    ], ($this->auth)())->assertStatus(422);
});
