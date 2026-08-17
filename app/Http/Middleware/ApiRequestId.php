<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stamp every API response with an id, and remember it (§17).
 *
 * A partner's bug report arrives as "request 6f3a… failed" and nothing
 * else. Without this, answering it means asking them to reproduce; with
 * it, the answer is one lookup.
 */
class ApiRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        $started = microtime(true);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        try {
            /** @var ApiClient|null $client */
            $client = $request->attributes->get('api_client');

            DB::table('api_request_logs')->insert([
                'api_client_id' => $client?->id,
                'request_id' => $requestId,
                'method' => $request->method(),
                // The path only. Query strings carry guest emails on some
                // endpoints, and a log is not a place to keep those.
                'path' => Str::limit($request->path(), 250, ''),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Logging a request must never be the reason one fails.
        }

        return $response;
    }
}
