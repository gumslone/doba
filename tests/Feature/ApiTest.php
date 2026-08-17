<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 12500, 'total_units' => 2,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);

    $this->checkIn = CarbonImmutable::today()->addDays(20);

    foreach (range(0, 6) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->checkIn->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    ['client' => $this->client, 'secret' => $this->secret] = ApiClient::issue(
        'Test partner',
        ApiClient::SCOPES,
    );

    $this->auth = fn (array $extra = []): array => array_merge([
        'X-Api-Key-Id' => $this->client->key_id,
        'X-Api-Secret' => $this->secret,
    ], $extra);

    $this->payload = fn (array $overrides = []): array => array_merge([
        'room_type' => 'DBL',
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'adults' => 2,
        'guest' => ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
    ], $overrides);
});

it('refuses everything without a valid key, and says the same thing every time', function (): void {
    $unauthorized = [
        [],
        ['X-Api-Key-Id' => 'dk_nope', 'X-Api-Secret' => 'whatever'],
        ['X-Api-Key-Id' => $this->client->key_id, 'X-Api-Secret' => 'wrong'],
    ];

    foreach ($unauthorized as $headers) {
        $response = $this->getJson('/api/v1/hotel', $headers)->assertStatus(401);

        // Identical whether the key id is unknown or the secret is wrong:
        // telling a caller which half they got right is an oracle for
        // enumerating the other.
        expect($response->json('type'))->toBe('https://docs.doba.dev/problems/unauthorized')
            ->and($response->headers->get('Content-Type'))->toContain('application/problem+json');
    }
});

it('enforces scopes per route', function (): void {
    ['client' => $readOnly, 'secret' => $secret] = ApiClient::issue('Widget', ['availability:read']);

    $headers = ['X-Api-Key-Id' => $readOnly->key_id, 'X-Api-Secret' => $secret];

    $this->getJson('/api/v1/availability?from='.$this->checkIn->toDateString().'&to='.$this->checkIn->addDays(3)->toDateString(), $headers)
        ->assertOk();

    // A key that may read availability may not take a booking with it.
    $this->postJson('/api/v1/bookings', ($this->payload)(), $headers + ['Idempotency-Key' => 'k1'])
        ->assertStatus(403)
        ->assertJsonPath('type', 'https://docs.doba.dev/problems/forbidden');
});

it('refuses a revoked or expired key', function (): void {
    $this->getJson('/api/v1/hotel', ($this->auth)())->assertOk();

    $this->client->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
    $this->getJson('/api/v1/hotel', ($this->auth)())->assertStatus(401);

    $this->client->forceFill(['revoked_at' => null, 'expires_at' => CarbonImmutable::now()->subDay()])->save();
    $this->getJson('/api/v1/hotel', ($this->auth)())->assertStatus(401);
});

it('honours an IP allowlist', function (): void {
    $this->client->forceFill(['ip_allowlist' => ['203.0.113.4']])->save();

    // Distinguishable from a bad key: a partner whose egress address
    // changed needs to know that, not rotate a key that was fine.
    $this->getJson('/api/v1/hotel', ($this->auth)())
        ->assertStatus(403)
        ->assertJsonPath('type', 'https://docs.doba.dev/problems/forbidden');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
        ->getJson('/api/v1/hotel', ($this->auth)())
        ->assertOk();
});

it('returns money as minor units and a currency, never a decimal string', function (): void {
    $response = $this->getJson('/api/v1/room-types', ($this->auth)())->assertOk();

    // The rule that removes an entire category of integration bug: a
    // partner cannot parse this as a float and lose a cent per night.
    expect($response->json('data.0.default_rate'))->toBe(['amount' => 12500, 'currency' => 'EUR']);
});

it('stamps every response with a request id and logs it', function (): void {
    $response = $this->getJson('/api/v1/hotel', ($this->auth)())->assertOk();

    $requestId = $response->headers->get('X-Request-Id');

    expect($requestId)->toBeString();

    // A partner's bug report arrives as a request id and nothing else.
    $log = DB::table('api_request_logs')->where('request_id', $requestId)->first();

    expect($log)->not->toBeNull()
        ->and($log->path)->toBe('api/v1/hotel')
        ->and($log->status)->toBe(200)
        ->and($log->api_client_id)->toBe($this->client->id);
});

it('takes a booking through the same service the website uses', function (): void {
    $response = $this->postJson('/api/v1/bookings', ($this->payload)(), ($this->auth)(['Idempotency-Key' => 'abc-123']))
        ->assertStatus(201);

    $reference = $response->json('data.reference');
    $booking = Booking::sole();

    expect($booking->reference)->toBe($reference)
        ->and($booking->source)->toBe('api')
        ->and($response->json('data.total'))->toBe(['amount' => 25000, 'currency' => 'EUR'])
        // Dates, not timestamps: a check-in is a date.
        ->and($response->json('data.check_in'))->toBe($this->checkIn->toDateString());

    // And it took inventory, through the same locking path as the funnel.
    expect(Availability::query()->where('date', $this->checkIn->toDateString())->value('held'))->toBe(1);
});

it('replays an idempotent retry instead of booking a second room', function (): void {
    $headers = ($this->auth)(['Idempotency-Key' => 'retry-me']);

    $first = $this->postJson('/api/v1/bookings', ($this->payload)(), $headers)->assertStatus(201);
    $second = $this->postJson('/api/v1/bookings', ($this->payload)(), $headers)->assertStatus(201);

    // A partner whose request timed out retries. One booking, and the
    // identical response — byte for byte, not merely similar.
    expect($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotent-Replay'))->toBe('true')
        ->and(Booking::query()->count())->toBe(1);
});

it('refuses the same key with a different body', function (): void {
    $headers = ($this->auth)(['Idempotency-Key' => 'same-key']);

    $this->postJson('/api/v1/bookings', ($this->payload)(), $headers)->assertStatus(201);

    // That is a bug in the caller, not a retry, and replaying the old
    // response would hide it.
    $this->postJson('/api/v1/bookings', ($this->payload)(['adults' => 1]), $headers)
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://docs.doba.dev/problems/idempotency-key-reused');

    expect(Booking::query()->count())->toBe(1);
});

it('requires an idempotency key at all', function (): void {
    $this->postJson('/api/v1/bookings', ($this->payload)(), ($this->auth)())
        ->assertStatus(400)
        ->assertJsonPath('type', 'https://docs.doba.dev/problems/idempotency-key-required');

    expect(Booking::query()->count())->toBe(0);
});

it('reports a sold-out stay as its own problem type', function (): void {
    // Take both rooms.
    Availability::query()->update(['booked' => 2]);

    $response = $this->postJson('/api/v1/bookings', ($this->payload)(), ($this->auth)(['Idempotency-Key' => 'k9']))
        ->assertStatus(409);

    // Its own type, because "the room went while you were deciding" is
    // the one failure a booking partner must handle specifically.
    expect($response->json('type'))->toBe('https://docs.doba.dev/problems/no-availability')
        ->and($response->json('date'))->toBe($this->checkIn->toDateString());
});

it('returns validation errors as a problem document with a field map', function (): void {
    $response = $this->postJson('/api/v1/bookings', ['room_type' => 'NOPE'], ($this->auth)(['Idempotency-Key' => 'k2']))
        ->assertStatus(422);

    expect($response->json('type'))->toBe('https://docs.doba.dev/problems/validation-failed')
        ->and($response->json('errors'))->toHaveKeys(['room_type', 'check_in', 'guest.email']);
});

it('cancels and reports the refund from the snapshot', function (): void {
    $this->postJson('/api/v1/bookings', ($this->payload)(), ($this->auth)(['Idempotency-Key' => 'k3']))->assertStatus(201);

    $reference = Booking::sole()->reference;

    $response = $this->postJson('/api/v1/bookings/'.$reference.'/cancel', [], ($this->auth)())->assertOk();

    expect($response->json('data.status'))->toBe('cancelled')
        ->and($response->json('refund.currency'))->toBe('EUR')
        // Reported beside the refund, because a refund is capped at what
        // was paid — and a bare zero on an unpaid booking reads like the
        // guest forfeited the stay.
        ->and($response->json('paid'))->toBe(['amount' => 0, 'currency' => 'EUR'])
        ->and($response->json('refund'))->toBe(['amount' => 0, 'currency' => 'EUR']);

    // A second cancel is a conflict, not a second refund.
    $this->postJson('/api/v1/bookings/'.$reference.'/cancel', [], ($this->auth)())
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://docs.doba.dev/problems/not-cancellable');
});

it('pages the pull endpoint with a cursor, not an offset', function (): void {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/bookings', ($this->payload)([
            'check_in' => $this->checkIn->addDays($i)->toDateString(),
            'check_out' => $this->checkIn->addDays($i + 1)->toDateString(),
            'guest' => ['email' => "g{$i}@example.com", 'first_name' => 'G', 'last_name' => (string) $i],
        ]), ($this->auth)(['Idempotency-Key' => 'page-'.$i]))->assertStatus(201);
    }

    $first = $this->getJson('/api/v1/bookings?limit=2', ($this->auth)())->assertOk();

    expect($first->json('data'))->toHaveCount(2)
        ->and($first->json('next_cursor'))->toBeString();

    $second = $this->getJson('/api/v1/bookings?limit=2&cursor='.$first->json('next_cursor'), ($this->auth)())->assertOk();

    // Offset pagination over a table being written to skips rows and
    // repeats others; a cursor cannot.
    $seen = array_merge(
        array_column($first->json('data'), 'reference'),
        array_column($second->json('data'), 'reference'),
    );

    expect($seen)->toHaveCount(4)
        ->and(array_unique($seen))->toHaveCount(4);
});

it('filters the pull endpoint by what changed since', function (): void {
    $this->postJson('/api/v1/bookings', ($this->payload)(), ($this->auth)(['Idempotency-Key' => 'k4']))->assertStatus(201);

    // urlencode: an ISO 8601 offset contains a '+', which a query string
    // reads as a space — the kind of thing a partner hits once and never
    // forgets.
    $future = urlencode(CarbonImmutable::now()->addHour()->toIso8601String());
    $past = urlencode(CarbonImmutable::now()->subHour()->toIso8601String());

    expect($this->getJson('/api/v1/bookings?updated_since='.$future, ($this->auth)())->json('data'))->toBe([]);
    expect($this->getJson('/api/v1/bookings?updated_since='.$past, ($this->auth)())->json('data'))
        ->toHaveCount(1);
});

it('marks a sandbox booking so nobody mistakes it for a guest', function (): void {
    ['client' => $sandbox, 'secret' => $secret] = ApiClient::issue('Sandbox', ApiClient::SCOPES, sandbox: true);

    $this->postJson('/api/v1/bookings', ($this->payload)(), [
        'X-Api-Key-Id' => $sandbox->key_id,
        'X-Api-Secret' => $secret,
        'Idempotency-Key' => 'sandbox-1',
    ])->assertStatus(201);

    expect(Booking::sole()->internal_notes)->toContain('sandbox');
});

it('never returns the secret, and shows it exactly once when issued', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post('/admin/api', ['name' => 'Channex', 'scopes' => ['bookings:read']])
        ->assertRedirect('/admin/api');

    $secret = session('api_secret');

    expect($secret)->toBeString();

    $this->actingAs($admin)->get('/admin/api')->assertOk()->assertSee($secret);

    // Gone on the next load: it is hashed, and nobody can read it back.
    $this->actingAs($admin)->get('/admin/api')->assertOk()->assertDontSee($secret);

    expect(ApiClient::query()->where('name', 'Channex')->value('secret_hash'))->not->toBe($secret);
});

it('keeps API key management behind the admin session', function (): void {
    $this->get('/admin/api')->assertRedirect('/admin/login');
    $this->post('/admin/api', [])->assertRedirect('/admin/login');
});
