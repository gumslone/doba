<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Booking\BookingService;
use App\Domain\Booking\NoAvailabilityException;
use App\Domain\Booking\PromoCodeException;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ApiIdempotencyKey;
use App\Models\Booking;
use App\Models\PromoCode;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\Api\Problem;
use App\Support\Api\Wire;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bookings over the API (§17).
 *
 * A thin layer over BookingService: the locking, the restriction checks,
 * the price snapshots and the state machine all stay where the website
 * uses them. Nothing here decides whether a room is free.
 */
class BookingController extends Controller
{
    public function store(Request $request, BookingService $bookings): Response
    {
        $client = $this->client($request);
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            // Required, not optional. A partner whose request times out
            // will retry, and a booking endpoint that cannot tell a retry
            // from a second booking sells the room twice.
            return Problem::make(
                'idempotency-key-required',
                'POST /bookings requires an Idempotency-Key header.',
                400,
            );
        }

        $hash = hash('sha256', json_encode($request->all()) ?: '');
        $existing = ApiIdempotencyKey::query()
            ->where('api_client_id', $client->id)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            if (! hash_equals($existing->request_hash, $hash)) {
                // Same key, different body. That is a bug in the caller,
                // and replaying the old response would hide it.
                return Problem::conflict(
                    'idempotency-key-reused',
                    'This Idempotency-Key was already used for a different request.',
                );
            }

            // Returned verbatim, byte for byte: a partner that checksums
            // or diffs responses must see the same one it saw before.
            return response($existing->response, $existing->status, [
                'Content-Type' => 'application/json',
                'Idempotent-Replay' => 'true',
            ]);
        }

        $validator = validator($request->all(), [
            'room_type' => ['required', 'string', 'exists:room_types,code'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'units' => ['nullable', 'integer', 'min:1', 'max:20'],
            'rate_plan' => ['nullable', 'string', 'exists:rate_plans,code'],
            'promo_code' => ['nullable', 'string', 'max:32'],
            'guest.email' => ['required', 'email:rfc', 'max:254'],
            'guest.first_name' => ['required', 'string', 'max:120'],
            'guest.last_name' => ['required', 'string', 'max:120'],
            'guest.phone' => ['nullable', 'string', 'max:64'],
            'guest.country' => ['nullable', 'string', 'size:2'],
            'locale' => ['nullable', Rule::in((array) config('doba.locales', ['en']))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return Problem::validation($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $roomType = RoomType::query()->where('code', $data['room_type'])->sole();

        try {
            $booking = $bookings->place(
                $roomType,
                CarbonImmutable::parse($data['check_in']),
                CarbonImmutable::parse($data['check_out']),
                [
                    'email' => $data['guest']['email'],
                    'first_name' => $data['guest']['first_name'],
                    'last_name' => $data['guest']['last_name'],
                    'phone' => $data['guest']['phone'] ?? null,
                    'country' => $data['guest']['country'] ?? null,
                ],
                adults: (int) $data['adults'],
                children: (int) ($data['children'] ?? 0),
                units: (int) ($data['units'] ?? 1),
                locale: $data['locale'] ?? config('app.locale'),
                ratePlan: isset($data['rate_plan'])
                    ? RatePlan::query()->where('code', $data['rate_plan'])->first()
                    : null,
                promoCode: isset($data['promo_code'])
                    ? PromoCode::findByCode($data['promo_code'])
                    : null,
            );
        } catch (NoAvailabilityException $e) {
            // The date, not the sentence: a partner branches on the
            // field, and the message is for a human reading a log.
            return Problem::unavailable($e->date);
        } catch (PromoCodeException $e) {
            return Problem::validation(['promo_code' => [__($e->reasonKey)]]);
        }

        $booking->forceFill([
            // Where it came from, so the channel mix in the reports tells
            // the truth about how much business the API brings.
            'source' => 'api',
            'guest_notes' => $data['notes'] ?? null,
            // A sandbox key's bookings are marked, so nobody mistakes an
            // integration test for a guest arriving on Friday.
            'internal_notes' => $client->sandbox ? 'Created by a sandbox API key.' : null,
        ])->save();

        $body = (string) json_encode(['data' => $this->present($booking->fresh())]);

        ApiIdempotencyKey::query()->create([
            'api_client_id' => $client->id,
            'key' => $key,
            'request_hash' => $hash,
            'status' => 201,
            'response' => $body,
        ]);

        return response($body, 201, ['Content-Type' => 'application/json']);
    }

    /**
     * The pull endpoint a channel manager polls.
     *
     * Cursor-paginated on purpose: offset pagination over a table that is
     * actively being written to skips rows and repeats others, and a
     * partner paging through it loses bookings without ever seeing an
     * error.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = validator($request->query(), [
            'updated_since' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(array_column(BookingStatus::cases(), 'value'))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Problem::validation($validator->errors()->toArray());
        }

        $limit = (int) $request->query('limit', 50);

        $page = Booking::query()
            ->with(['guest', 'rooms.roomType'])
            ->when($request->query('updated_since'), fn ($q, $since) => $q->where('updated_at', '>=', CarbonImmutable::parse($since)))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $request->query('cursor'));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Booking $b): array => $this->present($b))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(string $reference): JsonResponse
    {
        $booking = Booking::query()
            ->with(['guest', 'rooms.roomType'])
            ->where('reference', $reference)
            ->first();

        return $booking === null
            ? Problem::notFound('No booking with that reference.')
            : response()->json(['data' => $this->present($booking)]);
    }

    public function cancel(string $reference, BookingService $bookings): JsonResponse
    {
        $booking = Booking::query()->where('reference', $reference)->first();

        if ($booking === null) {
            return Problem::notFound('No booking with that reference.');
        }

        if (! $booking->status->canTransitionTo(BookingStatus::Cancelled)) {
            return Problem::conflict('not-cancellable', 'This booking can no longer be cancelled.');
        }

        // Computed from the snapshot on each room, never from today's
        // rate plan (§7): the guest is owed what they agreed to.
        $refund = $bookings->refundableAmount($booking);
        $paid = $booking->paid_amount;

        $bookings->transition($booking, BookingStatus::Cancelled, 'Cancelled over the API');

        return response()->json([
            'data' => $this->present($booking->fresh()),
            'refund' => Wire::money($refund),
            // Alongside it, because a refund is capped at what was
            // actually paid: a bare zero on an unpaid booking reads like
            // the guest forfeited the stay, when nothing was ever taken.
            'paid' => Wire::money($paid),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function present(Booking $booking): array
    {
        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'source' => $booking->source,
            // Dates, never timestamps: a check-in is a date (§17).
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'nights' => $booking->nights,
            'adults' => $booking->adults,
            'children' => $booking->children,
            'rooms' => $booking->rooms->map(static fn ($room): array => [
                'room_type' => $room->roomType?->code,
                'price' => Wire::money($room->price_total),
                'refundable' => (bool) $room->refundable_snapshot,
            ])->values(),
            'guest' => [
                'first_name' => $booking->guest?->first_name,
                'last_name' => $booking->guest?->last_name,
                'email' => $booking->guest?->email,
            ],
            'subtotal' => Wire::money($booking->subtotal),
            'extras_total' => Wire::money($booking->extras_total),
            'discount_total' => Wire::money($booking->discount_total),
            'total' => Wire::money($booking->total),
            'balance_due' => Wire::money($booking->balance_due),
            'created_at' => $booking->created_at?->toIso8601String(),
            'updated_at' => $booking->updated_at?->toIso8601String(),
        ];
    }

    protected function client(Request $request): ApiClient
    {
        /** @var ApiClient $client */
        $client = $request->attributes->get('api_client');

        return $client;
    }
}
