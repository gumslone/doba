<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Availability\AvailabilityService;
use App\Support\Directory\PropertyDescriptor;
use App\Support\Routing\Localization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Being findable (§21).
 *
 * A Doba install is one hotel on its own domain, which is what makes it
 * the hotel's own — and also what makes it invisible. A guest looking for
 * "somewhere in the Tyrol in September" has no list to look at, and that
 * absence is the one real thing an independent hotel gives up by not
 * being on a portal.
 *
 * These two endpoints close that gap without reopening the door the
 * portals came through:
 *
 *  - `/.well-known/doba.json` says who this hotel is and what it sells.
 *  - `/api/directory/quote` prices a set of dates, live.
 *
 * **Neither needs a credential**, and that is deliberate rather than
 * careless. Both return exactly what the hotel's own website already
 * shows any visitor — the same rooms, the same photos, the same prices,
 * from the same services. Requiring a key would protect nothing and would
 * mean every hub had to be onboarded by hand, which is precisely the
 * gatekeeping this is meant to avoid.
 *
 * What no aggregator gets is the booking. Every quote carries a link into
 * this hotel's own funnel, and the guest finishes there.
 */
class DirectoryController extends Controller
{
    /**
     * The property descriptor a hub reads to learn this hotel exists.
     *
     * Conditional GET is honoured properly, because a hub polling a few
     * thousand installs nightly should mostly be told "nothing changed"
     * and hang up.
     */
    public function wellKnown(Request $request, PropertyDescriptor $descriptor): SymfonyResponse
    {
        if (! PropertyDescriptor::isEnabled()) {
            // 404, not 403: an install that has not opted in should look
            // like one that was never built, not like one hiding something.
            abort(404);
        }

        $updated = $descriptor->updatedAt();
        $etag = '"'.substr(hash('sha256', (string) $updated->getTimestamp().config('app.url')), 0, 32).'"';

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response()->json($descriptor->toArray(), 200, [
            'ETag' => $etag,
            'Last-Modified' => $updated->toRfc7231String(),
            // Public: there is nothing per-visitor in it, so a CDN or the
            // hub's own cache is welcome to keep a copy.
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Live prices for a set of dates.
     *
     * The same `AvailabilityService::search` the website's own funnel
     * calls, so an aggregator cannot be shown a room the hotel would
     * refuse — minimum stay, closed-to-arrival, closures and occupancy
     * are all already applied by the time anything gets here.
     */
    public function quote(Request $request, AvailabilityService $availability): JsonResponse
    {
        if (! PropertyDescriptor::isEnabled()) {
            abort(404);
        }

        $validator = validator($request->query(), [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_request', 'errors' => $validator->errors()->toArray()], 422);
        }

        $checkIn = CarbonImmutable::parse($request->query('check_in'))->startOfDay();
        $checkOut = CarbonImmutable::parse($request->query('check_out'))->startOfDay();
        $adults = (int) $request->query('adults', 2);
        $children = (int) $request->query('children', 0);

        $maxNights = (int) config('doba.directory.max_nights', 30);

        if ($checkIn->diffInDays($checkOut) > $maxNights) {
            return response()->json([
                'error' => 'stay_too_long',
                'max_nights' => $maxNights,
            ], 422);
        }

        if (($reason = $availability->validateStay($checkIn, $checkOut)) !== null) {
            // Not an error and not an empty result: "we do not take
            // bookings that far out" is a different fact from "we are
            // full", and a hub that cannot tell them apart will keep
            // asking, or will show the hotel as sold out when it is not.
            return response()->json([
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'bookable' => false,
                'reason' => $reason,
                'offers' => [],
            ]);
        }

        $offers = $availability->search($checkIn, $checkOut, $adults, $children);
        $currency = (string) config('doba.currency');
        $nights = (int) $checkIn->diffInDays($checkOut);

        return response()->json([
            'install_id' => PropertyDescriptor::installId(),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'currency' => $currency,
            'bookable' => $offers !== [],
            'offers' => array_map(function (array $offer) use ($checkIn, $checkOut, $adults, $children, $currency, $request): array {
                return [
                    'room_type' => $offer['room_type']->code,
                    'name' => $offer['room_type']->t('name'),
                    'max_occupancy' => $offer['room_type']->max_occupancy,
                    'total' => ['amount' => (int) $offer['total'], 'currency' => $currency],
                    'per_night' => ['amount' => (int) $offer['per_night'], 'currency' => $currency],
                    'units_left' => $offer['units_left'],
                    // Where the guest goes. Dates carried through, so the
                    // funnel opens on the stay they searched for rather
                    // than on an empty form asking them to type it again.
                    'booking_url' => $this->bookingUrl($checkIn, $checkOut, $adults, $children, $request),
                ];
            }, $offers),
        ], 200, ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300']);
    }

    /**
     * A deep link into this hotel's funnel, carrying the referrer.
     *
     * `ref` is echoed back from the caller rather than invented here, and
     * it is what lets the hotel see in its own channel-mix report how much
     * business a directory actually sent. A listing nobody can measure is
     * a listing nobody can decide to keep.
     */
    protected function bookingUrl(
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        int $adults,
        int $children,
        Request $request,
    ): string {
        $query = [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'adults' => $adults,
            'children' => $children,
        ];

        $ref = $request->query('ref');

        if (is_string($ref) && preg_match('/^[a-z0-9._-]{1,32}$/i', $ref) === 1) {
            $query['ref'] = $ref;
        }

        return Localization::route('booking.search', $query, (string) config('app.locale'));
    }
}
