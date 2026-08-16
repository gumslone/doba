<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Availability\AvailabilityService;
use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public calendar-widget feed (§6): a small JSON payload the Alpine
 * date picker renders — no heavy calendar library, no server rendering of
 * 62 cells. This is the widget's endpoint, not the partner API; /api/v1
 * (§17) arrives in phase 6 with its own authentication.
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request, AvailabilityService $availability): JsonResponse
    {
        $validated = $request->validate([
            'room_type' => ['required', 'integer', 'exists:room_types,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from'],
        ]);

        $from = CarbonImmutable::parse($validated['from']);
        $to = CarbonImmutable::parse($validated['to']);

        // Two months is what the picker shows; a year is a scraper. Cap the
        // range instead of erroring so a widget asking for a little too
        // much still works.
        if ($from->diffInDays($to) > 92) {
            $to = $from->addDays(92);
        }

        $roomType = RoomType::query()->active()->find($validated['room_type']);

        if ($roomType === null) {
            return response()->json(['days' => []], 404);
        }

        return response()
            ->json(['days' => $availability->calendar($roomType, $from, $to)])
            // Prices and availability drift slowly; a shared cache absorbing
            // repeat widget loads for a minute is free capacity.
            ->header('Cache-Control', 'public, max-age=60');
    }
}
