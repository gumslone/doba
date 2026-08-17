<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Pricing\RateResolver;
use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Models\RoomType;
use App\Support\Api\Problem;
use App\Support\Api\Wire;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read path most partners live on (§17).
 *
 * Every figure here comes from AvailabilityService — the same one the
 * website's own funnel calls. The day this grows its own idea of what is
 * bookable is the day it sells a room the website thinks is taken, and
 * that bug is found by a guest rather than by us.
 */
class AvailabilityController extends Controller
{
    public function index(Request $request, RateResolver $rates): JsonResponse
    {
        $validator = validator($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'room_type' => ['nullable', 'string', 'exists:room_types,code'],
        ]);

        if ($validator->fails()) {
            return Problem::validation($validator->errors()->toArray());
        }

        $from = CarbonImmutable::parse($request->query('from'))->startOfDay();
        $to = CarbonImmutable::parse($request->query('to'))->startOfDay();

        if ($from->diffInDays($to) > 370) {
            // A bounded answer beats a timeout: a partner asking for five
            // years gets told the limit rather than waiting for a request
            // that will be killed anyway.
            return Problem::validation(['to' => ['The range must be 370 days or fewer.']]);
        }

        $types = RoomType::query()
            ->active()
            ->when($request->query('room_type'), fn ($query, $code) => $query->where('code', $code))
            ->ordered()
            ->get();

        $rows = Availability::query()
            ->whereIn('room_type_id', $types->pluck('id'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get()
            ->groupBy('room_type_id');

        $data = [];

        foreach ($types as $type) {
            $nights = [];

            foreach ($rows->get($type->id, collect()) as $row) {
                $nights[] = [
                    'date' => $row->date->toDateString(),
                    // What a new booking could take, holds included: the
                    // number a partner should decide on, not the raw
                    // allotment.
                    'available' => $row->unitsLeft(),
                    'price' => Wire::money($rates->nightlyPrice($type, $row->date, $row)),
                    'min_stay' => $row->min_stay,
                    'max_stay' => $row->max_stay,
                    'closed' => $row->closed,
                    'closed_to_arrival' => $row->closed_to_arrival,
                    'closed_to_departure' => $row->closed_to_departure,
                ];
            }

            $data[] = ['room_type' => $type->code, 'nights' => $nights];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Bookable stays for a set of dates — the same search the website runs.
     */
    public function search(Request $request, AvailabilityService $availability): JsonResponse
    {
        $validator = validator($request->query(), [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        if ($validator->fails()) {
            return Problem::validation($validator->errors()->toArray());
        }

        $checkIn = CarbonImmutable::parse($request->query('check_in'))->startOfDay();
        $checkOut = CarbonImmutable::parse($request->query('check_out'))->startOfDay();

        if (($reason = $availability->validateStay($checkIn, $checkOut)) !== null) {
            return Problem::validation(['check_in' => [__($reason)]]);
        }

        $offers = $availability->search(
            $checkIn,
            $checkOut,
            (int) $request->query('adults', 2),
            (int) $request->query('children', 0),
        );

        return response()->json([
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'nights' => (int) $checkIn->diffInDays($checkOut),
            'data' => array_map(static fn (array $offer): array => [
                'room_type' => $offer['room_type']->code,
                'total' => Wire::money($offer['total']),
                'per_night' => Wire::money($offer['per_night']),
                'units_left' => $offer['units_left'],
                'rate_plans' => array_map(static fn (array $plan): array => [
                    'code' => $plan['plan']->code,
                    'total' => Wire::money($plan['total']),
                    'refundable' => $plan['plan']->refundable,
                    'cancellation_hours' => $plan['plan']->cancellation_hours,
                ], $offer['rate_plans']),
            ], $offers),
        ]);
    }
}
