<?php

declare(strict_types=1);

use App\Console\Commands\GenerateOpenApi;
use App\Http\Middleware\AuthenticateApiClient;
use App\Models\ApiClient;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\Contract;

/**
 * The contract, enforced (§17).
 *
 * Two halves. The structural tests make sure openapi.yaml describes the
 * routes that actually exist — no undocumented endpoint, no documented
 * ghost, no scope the docs get wrong. The contract tests put real
 * responses through the schemas, so renaming a field in a controller
 * turns CI red rather than a partner's parser.
 */
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

    ['client' => $this->client, 'secret' => $this->secret] = ApiClient::issue('Contract partner', ApiClient::SCOPES);

    $this->auth = fn (array $extra = []): array => array_merge([
        'X-Api-Key-Id' => $this->client->key_id,
        'X-Api-Secret' => $this->secret,
    ], $extra);

    $this->book = fn (string $key = 'contract-1'): Booking => tap(
        Booking::query()->where('reference', $this->postJson('/api/v1/bookings', [
            'room_type' => 'DBL',
            'check_in' => $this->checkIn->toDateString(),
            'check_out' => $this->checkIn->addDays(2)->toDateString(),
            'adults' => 2,
            'guest' => ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
        ], ($this->auth)(['Idempotency-Key' => $key]))->assertStatus(201)->json('data.reference'))->sole(),
    );
});

/*
|--------------------------------------------------------------------------
| The spec describes the API that exists
|--------------------------------------------------------------------------
*/

it('has a committed openapi.json matching the yaml it came from', function (): void {
    // Partners fetch the JSON. If it can go stale, it will, and the first
    // person to notice is somebody integrating against a lie.
    expect(file_get_contents(base_path(GenerateOpenApi::TARGET)))
        ->toBe(GenerateOpenApi::render());
});

it('documents every route the API actually serves', function (): void {
    foreach (apiRoutes() as [$method, $path, $route]) {
        // Fails the moment somebody adds an endpoint without writing it
        // down — which is the only moment anybody will remember to.
        expect(Contract::operation($method, $path))->toBeObject();
    }
});

it('documents no route the API does not serve', function (): void {
    $real = array_map(static fn (array $r): string => $r[0].' '.$r[1], apiRoutes());

    foreach (Contract::spec()->paths as $path => $operations) {
        foreach ((array) $operations as $method => $_) {
            expect($real)->toContain(strtoupper($method).' '.$path);
        }
    }
});

it('names the same scope the route enforces', function (): void {
    foreach (apiRoutes() as [$method, $path, $route]) {
        $declared = Contract::operation($method, $path)->{'x-doba-scope'} ?? null;

        // Documentation about permissions that nothing checks is worse
        // than none: a partner requests the scope the docs name, and then
        // gets a 403 they cannot explain.
        expect($declared)->toBe(routeScope($route), sprintf('%s %s', $method, $path));
    }
});

it('describes every response it returns, including the errors', function (): void {
    foreach (Contract::spec()->paths as $path => $operations) {
        foreach ((array) $operations as $method => $operation) {
            // strval, because casting an object whose properties are
            // "200" and "401" hands back integer keys.
            $statuses = array_map(strval(...), array_keys((array) $operation->responses));

            // Every authenticated route can refuse, and a partner writing
            // error handling should not have to guess the shape.
            expect($statuses)->toContain('401')->toContain('403');
        }
    }
});

/*
|--------------------------------------------------------------------------
| The API returns what the spec describes
|--------------------------------------------------------------------------
*/

it('serves the hotel and its room types as documented', function (): void {
    Contract::assertMatches($this->getJson('/api/v1/hotel', ($this->auth)())->assertOk(), 'GET', '/hotel');
    Contract::assertMatches($this->getJson('/api/v1/room-types', ($this->auth)())->assertOk(), 'GET', '/room-types');
});

it('serves availability and search as documented', function (): void {
    $range = '?from='.$this->checkIn->toDateString().'&to='.$this->checkIn->addDays(3)->toDateString();

    Contract::assertMatches($this->getJson('/api/v1/availability'.$range, ($this->auth)())->assertOk(), 'GET', '/availability');

    $search = '?check_in='.$this->checkIn->toDateString()
        .'&check_out='.$this->checkIn->addDays(2)->toDateString().'&adults=2';

    Contract::assertMatches($this->getJson('/api/v1/search'.$search, ($this->auth)())->assertOk(), 'GET', '/search');
});

it('answers an ARI push as documented, refusals and all', function (): void {
    $push = ['updates' => [[
        'room_type' => 'DBL',
        'from' => $this->checkIn->toDateString(),
        'to' => $this->checkIn->addDays(3)->toDateString(),
        'allotment' => 1,
        'min_stay' => 2,
    ]]];

    Contract::assertMatches(
        $this->putJson('/api/v1/availability', $push, ($this->auth)())->assertOk(),
        'PUT',
        '/availability',
    );

    Contract::assertMatches(
        $this->putJson('/api/v1/rates', ['updates' => [[
            'room_type' => 'DBL',
            'from' => $this->checkIn->toDateString(),
            'to' => $this->checkIn->addDays(3)->toDateString(),
            'price' => 14000,
        ]]], ($this->auth)())->assertOk(),
        'PUT',
        '/rates',
    );

    // The refusal is part of the contract too, not an undocumented corner.
    Contract::assertMatches(
        $this->putJson('/api/v1/rates', ['updates' => [[
            'room_type' => 'DBL',
            'from' => $this->checkIn->toDateString(),
            'to' => $this->checkIn->toDateString(),
            'price' => 14000,
            'rate_plan' => 'BAR',
        ]]], ($this->auth)())->assertStatus(422),
        'PUT',
        '/rates',
    );
});

it('serves a booking, a list and a cancellation as documented', function (): void {
    $created = $this->postJson('/api/v1/bookings', [
        'room_type' => 'DBL',
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'adults' => 2,
        'guest' => ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
    ], ($this->auth)(['Idempotency-Key' => 'contract-booking']))->assertStatus(201);

    Contract::assertMatches($created, 'POST', '/bookings');

    // A pending booking carries a hold expiry; a cancelled one does not.
    // Both go through the same schema, so the nullability is enforced.
    expect($created->json('data.hold_expires_at'))->toBeString();

    $reference = $created->json('data.reference');

    Contract::assertMatches($this->getJson('/api/v1/bookings', ($this->auth)())->assertOk(), 'GET', '/bookings');
    Contract::assertMatches($this->getJson('/api/v1/bookings/'.$reference, ($this->auth)())->assertOk(), 'GET', '/bookings/{reference}');
    Contract::assertMatches(
        $this->postJson('/api/v1/bookings/'.$reference.'/cancel', [], ($this->auth)())->assertOk(),
        'POST',
        '/bookings/{reference}/cancel',
    );
});

it('serves the webhook endpoints as documented', function (): void {
    Queue::fake();

    Contract::assertMatches($this->getJson('/api/v1/webhooks', ($this->auth)())->assertOk(), 'GET', '/webhooks');

    $created = $this->postJson('/api/v1/webhooks', [
        'url' => 'https://partner.example.com/hooks/doba',
        'events' => ['booking.created', 'booking.cancelled'],
    ], ($this->auth)())->assertStatus(201);

    Contract::assertMatches($created, 'POST', '/webhooks');

    $id = $created->json('data.id');

    Contract::assertMatches(
        $this->postJson('/api/v1/webhooks/'.$id.'/test', [], ($this->auth)())->assertStatus(202),
        'POST',
        '/webhooks/{webhook}/test',
    );

    Contract::assertMatches(
        $this->deleteJson('/api/v1/webhooks/'.$id, [], ($this->auth)())->assertStatus(204),
        'DELETE',
        '/webhooks/{webhook}',
    );
});

it('shapes its problems the way the spec says, on every kind of failure', function (): void {
    // 401 — no credentials at all.
    Contract::assertMatches($this->getJson('/api/v1/hotel')->assertStatus(401), 'GET', '/hotel');

    // 403 — valid key, wrong scope.
    ['client' => $narrow, 'secret' => $secret] = ApiClient::issue('Read only', ['availability:read']);

    Contract::assertMatches(
        $this->getJson('/api/v1/hotel', ['X-Api-Key-Id' => $narrow->key_id, 'X-Api-Secret' => $secret])->assertStatus(403),
        'GET',
        '/hotel',
    );

    // 404 — a reference nobody holds.
    Contract::assertMatches(
        $this->getJson('/api/v1/bookings/NOPE-404', ($this->auth)())->assertStatus(404),
        'GET',
        '/bookings/{reference}',
    );

    // 422 — the errors bag, which partners surface to their own users.
    Contract::assertMatches(
        $this->getJson('/api/v1/availability?from=yesterday', ($this->auth)())->assertStatus(422),
        'GET',
        '/availability',
    );

    // 400 and 409 on the booking route: the two failures a booking
    // integration has to handle by name.
    Contract::assertMatches(
        $this->postJson('/api/v1/bookings', ['room_type' => 'DBL'], ($this->auth)())->assertStatus(400),
        'POST',
        '/bookings',
    );

    $this->postJson('/api/v1/bookings', [
        'room_type' => 'DBL',
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'adults' => 2,
        'guest' => ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
    ], ($this->auth)(['Idempotency-Key' => 'dup']))->assertStatus(201);

    Contract::assertMatches(
        $this->postJson('/api/v1/bookings', [
            'room_type' => 'DBL',
            'check_in' => $this->checkIn->toDateString(),
            'check_out' => $this->checkIn->addDays(2)->toDateString(),
            'adults' => 1,
            'guest' => ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        ], ($this->auth)(['Idempotency-Key' => 'dup']))->assertStatus(409),
        'POST',
        '/bookings',
    );
});

/**
 * Every partner route, as [method, spec path, route].
 *
 * @return array<int,array{0:string,1:string,2:RoutingRoute}>
 */
function apiRoutes(): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;   // Laravel's free companion to every GET
            }

            $found[] = [$method, '/'.substr($route->uri(), strlen('api/v1/')), $route];
        }
    }

    return $found;
}

/**
 * The scope the route itself enforces, read off its middleware.
 */
function routeScope(RoutingRoute $route): ?string
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && str_starts_with($middleware, AuthenticateApiClient::class.':')) {
            return substr($middleware, strlen(AuthenticateApiClient::class) + 1);
        }
    }

    return null;
}
