<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\ChannelSyncService;
use App\Models\RoomType;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The export half of §9: a subscribable calendar of nights this room type
 * cannot be sold.
 *
 * Unauthenticated by design — an OTA subscribes to a URL and cannot log
 * in — so the token in the path is the only credential, compared in
 * constant time, and the feed carries no guest data whatsoever.
 */
class IcalController extends Controller
{
    public function show(RoomType $roomType, string $token, ChannelSyncService $channels): Response
    {
        if ($roomType->ical_token === null || ! hash_equals($roomType->ical_token, $token)) {
            throw new NotFoundHttpException('Invalid calendar token.');
        }

        return response($channels->export($roomType), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$roomType->code.'.ics"',
            // Subscribers poll on their own schedule; a short cache keeps
            // a popular feed from being regenerated on every request
            // without letting it go meaningfully stale.
            'Cache-Control' => 'public, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
