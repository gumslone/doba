<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\BulkAvailabilityUpdate;
use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Support\Api\Problem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ARI push — Availability, Rates, Inventory (§17).
 *
 * `PUT` rather than `POST`, and meant literally: these are **idempotent
 * range writes**, not increments. A channel manager whose push timed out
 * must be able to send the identical body again and change nothing, which
 * is the difference between a retry and a hotel whose allotment drifts
 * every time the network hiccups.
 *
 * Both take a weekday mask, so "Saturdays in July, minimum stay three" is
 * one call rather than thirty-one.
 *
 * The writing itself goes through BulkAvailabilityUpdate — the same code
 * the admin grid uses, and the same code that refuses to set an allotment
 * below what is already booked and held. A partner pushing "2 rooms" onto
 * a night with three confirmed stays is told which night, rather than
 * quietly overselling it.
 */
class AriController extends Controller
{
    public function availability(Request $request, BulkAvailabilityUpdate $bulk): JsonResponse
    {
        $validator = validator($request->all(), [
            'updates' => ['required', 'array', 'min:1', 'max:200'],
            'updates.*.room_type' => ['required', 'string', 'exists:room_types,code'],
            'updates.*.from' => ['required', 'date_format:Y-m-d'],
            'updates.*.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:updates.*.from'],
            'updates.*.weekdays' => ['nullable', 'integer', 'min:1', 'max:127'],
            'updates.*.allotment' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'updates.*.closed' => ['nullable', 'boolean'],
            'updates.*.closed_to_arrival' => ['nullable', 'boolean'],
            'updates.*.closed_to_departure' => ['nullable', 'boolean'],
            'updates.*.min_stay' => ['nullable', 'integer', 'min:1', 'max:255'],
            'updates.*.max_stay' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        if ($validator->fails()) {
            return Problem::validation($validator->errors()->toArray());
        }

        return $this->applyAll($validator->validated()['updates'], $bulk, static fn (array $row): array => [
            'allotment' => $row['allotment'] ?? null,
            'closed' => $row['closed'] ?? null,
            'closed_to_arrival' => $row['closed_to_arrival'] ?? null,
            'closed_to_departure' => $row['closed_to_departure'] ?? null,
            'min_stay' => $row['min_stay'] ?? null,
            'max_stay' => $row['max_stay'] ?? null,
        ]);
    }

    public function rates(Request $request, BulkAvailabilityUpdate $bulk): JsonResponse
    {
        $validator = validator($request->all(), [
            'updates' => ['required', 'array', 'min:1', 'max:200'],
            'updates.*.room_type' => ['required', 'string', 'exists:room_types,code'],
            'updates.*.from' => ['required', 'date_format:Y-m-d'],
            'updates.*.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:updates.*.from'],
            'updates.*.weekdays' => ['nullable', 'integer', 'min:1', 'max:127'],
            // Minor units, like every other amount on the wire.
            'updates.*.price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'updates.*.rate_plan' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Problem::validation($validator->errors()->toArray());
        }

        $updates = $validator->validated()['updates'];

        foreach ($updates as $row) {
            if (($row['rate_plan'] ?? null) !== null) {
                // Said plainly rather than silently ignored. In this model
                // a rate plan is an ADJUSTMENT off the room's price (§7),
                // not an independent price — so accepting a per-plan rate
                // here would mean storing a number nothing ever reads, and
                // a partner would spend a week wondering why their push
                // had no effect.
                return Problem::make(
                    'rate-plan-not-pushable',
                    'Rate plans are adjustments off the room price, so a price cannot be pushed per plan. Push the room price and configure the plan adjustment in the admin.',
                    422,
                );
            }
        }

        return $this->applyAll($updates, $bulk, static fn (array $row): array => [
            'price' => (int) $row['price'],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $updates
     * @param  callable(array<string,mixed>):array<string,mixed>  $changes
     */
    protected function applyAll(array $updates, BulkAvailabilityUpdate $bulk, callable $changes): JsonResponse
    {
        $applied = 0;
        $refused = [];

        foreach ($updates as $row) {
            $roomType = RoomType::query()->where('code', $row['room_type'])->sole();

            $result = $bulk->apply(
                [$roomType->id],
                CarbonImmutable::parse($row['from']),
                CarbonImmutable::parse($row['to']),
                (int) ($row['weekdays'] ?? BulkAvailabilityUpdate::ALL_WEEK),
                $changes($row),
            );

            $applied += $result['updated'];

            foreach ($result['refused'] as $date) {
                $refused[] = ['room_type' => $row['room_type'], 'date' => $date];
            }
        }

        // 200 with the refusals listed rather than a blanket 409: a push
        // covering six months should not be thrown away because one night
        // in it is already oversold, and the partner needs to know which
        // night rather than which request.
        return response()->json([
            'nights_updated' => $applied,
            'refused' => $refused,
        ]);
    }
}
