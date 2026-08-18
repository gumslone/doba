<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\ApiIdempotencyKey;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use Carbon\CarbonImmutable;

/**
 * The window between "have I seen this key?" and writing it down (§17).
 *
 * A partner whose request times out retries — that is the entire reason
 * `Idempotency-Key` is required. But a retry that arrives while the first
 * request is still working lands in the gap between the SELECT that finds
 * no key and the INSERT that records one, and both requests then take a
 * booking. The unique index stops the second key row from being written;
 * it does not stop the second room from being sold.
 */
beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 12500, 'total_units' => 5,
    ]);

    $this->checkIn = CarbonImmutable::today()->addDays(20);

    foreach (range(0, 4) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->checkIn->addDays($i)->toDateString(),
            'allotment' => 5,
        ]);
    }

    ['client' => $this->client, 'secret' => $this->secret] = ApiClient::issue('Racer', ApiClient::SCOPES);

    $this->headers = [
        'X-Api-Key-Id' => $this->client->key_id,
        'X-Api-Secret' => $this->secret,
        'Idempotency-Key' => 'timed-out-and-retried',
    ];

    $this->payload = [
        'room_type' => 'DBL',
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'adults' => 2,
        'guest' => ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
    ];
});

it('takes one booking when a retry arrives while the first request is still working', function (): void {
    $retry = null;
    $reentered = false;

    // Re-enter the endpoint at the one moment that matters: the first
    // request has passed its key check and is mid-transaction, and has
    // not yet written the key down. This is exactly where a partner's
    // retry lands, and there is no way to reach it from outside.
    Booking::created(function () use (&$retry, &$reentered): void {
        if ($reentered) {
            return;   // set before the call, or the retry re-enters itself
        }

        $reentered = true;
        $retry = $this->postJson('/api/v1/bookings', $this->payload, $this->headers);
    });

    $first = $this->postJson('/api/v1/bookings', $this->payload, $this->headers);

    // One key, one booking. The retry may be told the original is still
    // in flight, or be given the original response — it must never be
    // given a second room.
    expect(Booking::query()->count())->toBe(1)
        ->and($retry?->getStatusCode())->not->toBe(201);

    // And the first request still answered its caller properly rather
    // than dying on the unique index it was about to violate.
    expect($first->getStatusCode())->toBe(201);
});

it('tells a retry that the first request is still working, rather than guessing', function (): void {
    ApiIdempotencyKey::claim($this->client->id, 'timed-out-and-retried', hash('sha256', json_encode($this->payload) ?: ''));

    $this->postJson('/api/v1/bookings', $this->payload, $this->headers)
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://docs.doba.dev/problems/idempotency-key-in-progress')
        ->assertHeader('Retry-After', '2');

    expect(Booking::query()->count())->toBe(0);
});

it('does not burn a key on a request that created nothing', function (): void {
    // A night that does not exist: refused, and nothing booked.
    $this->postJson('/api/v1/bookings', [...$this->payload, 'check_in' => $this->checkIn->addDays(40)->toDateString(), 'check_out' => $this->checkIn->addDays(42)->toDateString()], $this->headers)
        ->assertStatus(409);

    // The same key still works, because the failed attempt left nothing
    // behind to be idempotent about. Burning it would make one bad
    // request permanently unrepeatable.
    $this->postJson('/api/v1/bookings', $this->payload, $this->headers)->assertStatus(201);

    expect(Booking::query()->count())->toBe(1);
});

it('takes over a claim whose request died, but only once it is long dead', function (): void {
    $hash = hash('sha256', json_encode($this->payload) ?: '');

    ApiIdempotencyKey::claim($this->client->id, 'orphan', $hash);

    // Still warm: a request that is merely slow keeps its claim, or the
    // takeover would put back the very race it is here to avoid.
    expect(ApiIdempotencyKey::claim($this->client->id, 'orphan', $hash))->toBeFalse();

    ApiIdempotencyKey::query()->where('key', 'orphan')->update([
        'created_at' => CarbonImmutable::now()->subMinutes(ApiIdempotencyKey::STALE_AFTER_MINUTES + 1),
    ]);

    // Long dead: a worker was killed mid-booking, and without this the
    // partner could never use that key again.
    expect(ApiIdempotencyKey::claim($this->client->id, 'orphan', $hash))->toBeTrue()
        ->and(ApiIdempotencyKey::query()->where('key', 'orphan')->count())->toBe(1);
});
