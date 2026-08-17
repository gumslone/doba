<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Support\Api\Problem;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Key-pair authentication for the partner API (§17).
 *
 * Every rejection is the same shape and says the same thing, whether the
 * key id is unknown, the secret is wrong or the client was revoked an
 * hour ago. Telling a caller which half they got right is an oracle for
 * enumerating the other.
 */
class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $keyId = $request->header('X-Api-Key-Id');
        $secret = $request->header('X-Api-Secret');

        if (! is_string($keyId) || ! is_string($secret) || $keyId === '' || $secret === '') {
            return Problem::unauthorized();
        }

        $client = ApiClient::query()->where('key_id', $keyId)->first();

        // verify() is run even when no client matched, against a dummy
        // hash, so a wrong key id and a wrong secret take the same time.
        $ok = $client !== null
            ? $client->verify($secret)
            : $this->burnTime($secret);

        if ($client === null || ! $ok || ! $client->isUsable()) {
            return Problem::unauthorized();
        }

        if (! $client->allowsIp($request->ip())) {
            // Distinguishable, because a partner whose egress address
            // changed needs to know that is what happened rather than
            // rotating a key that was fine.
            return Problem::forbidden('This key is not allowed from this address.');
        }

        if ($scope !== null && ! $client->hasScope($scope)) {
            return Problem::forbidden(sprintf('This key does not have the "%s" scope.', $scope));
        }

        $request->attributes->set('api_client', $client);

        // Written without touching updated_at: a partner polling every
        // minute should not rewrite the row's timestamps forever.
        ApiClient::query()->whereKey($client->id)->update(['last_used_at' => CarbonImmutable::now()]);

        return $next($request);
    }

    protected function burnTime(string $secret): bool
    {
        // A hash of the right shape, so an unknown key id costs the same
        // as a known one with the wrong secret.
        return password_verify($secret, '$2y$12$usesomesillystringfore.Q0dQ8/Yl3cDQhcW3lYQGvYQ0kZG9C');
    }
}
