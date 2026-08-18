<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remember who sent this guest (§21).
 *
 * A directory listing nobody can measure is a listing nobody can decide
 * to keep. Without this, a hotel pays attention to an aggregator for a
 * season and has no way to answer the only question that matters — did it
 * bring anyone? — so the answer defaults to whoever argues loudest.
 *
 * Kept in the session rather than on the URL, because a guest who arrives
 * on the search page, reads about the rooms, changes their dates twice
 * and books an hour later has long since lost the query string. The
 * attribution belongs to the visit, not the first page of it.
 */
class CaptureReferral
{
    public const SESSION_KEY = 'doba_referral';

    public function handle(Request $request, Closure $next): Response
    {
        $ref = $request->query('ref');

        // Validated, not trusted: this lands in the booking's `source`,
        // which is read back into reports and shown to staff.
        if (is_string($ref) && preg_match('/^[a-z0-9._-]{1,32}$/i', $ref) === 1) {
            $request->session()->put(self::SESSION_KEY, strtolower($ref));
        }

        return $next($request);
    }
}
