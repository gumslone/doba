<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remembered response, so a retried request does not book a second room.
 *
 * @property string $key
 * @property string $request_hash
 * @property int|null $status NULL while the request is still in flight
 * @property string|null $response
 */
class ApiIdempotencyKey extends Model
{
    protected $fillable = ['api_client_id', 'key', 'request_hash', 'status', 'response'];

    protected $casts = [
        'status' => 'integer',
    ];

    /**
     * How long a claim may sit unfinished before another request may take
     * it over.
     *
     * This exists for the request that died — the worker killed, the
     * container recycled — because without it a single crash would burn
     * that key forever and the partner could never retry it. Well beyond
     * any real request: a booking takes milliseconds, and taking a claim
     * over while its owner is genuinely still working would put back the
     * race this whole mechanism removes.
     */
    public const STALE_AFTER_MINUTES = 5;

    /**
     * Claim a key, before doing the work it stands for.
     *
     * This is the ordering the whole guarantee rests on. The row used to
     * be written *after* the booking, which left the check and the write
     * a transaction apart — and a retry arriving in between passed the
     * check, because there was nothing yet to find. Both requests then
     * booked, and only the second one's INSERT hit the unique index: one
     * key, two rooms, and a 500 for the caller that did nothing wrong.
     *
     * `insertOrIgnore` rather than select-then-insert on purpose. The
     * unique index is the only arbiter that two connections both respect,
     * and asking the database "did I win?" is the one question that
     * cannot be raced.
     *
     * @return bool True if this request now owns the key.
     */
    public static function claim(int $clientId, string $key, string $hash): bool
    {
        $now = CarbonImmutable::now();

        $inserted = static::query()->insertOrIgnore([
            'api_client_id' => $clientId,
            'key' => $key,
            'request_hash' => $hash,
            'status' => null,
            'response' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return true;
        }

        // Somebody else holds it. Take it over only if their request is
        // long dead. One UPDATE, so two takeovers cannot both win: the
        // first moves created_at and the second's WHERE stops matching.
        return static::query()
            ->where('api_client_id', $clientId)
            ->where('key', $key)
            ->whereNull('status')
            ->where('created_at', '<', $now->subMinutes(self::STALE_AFTER_MINUTES))
            ->update([
                'request_hash' => $hash,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * Give the key back, because nothing was created under it.
     *
     * A partner that sent an invalid body, or asked for a night that had
     * just gone, should be able to fix it and retry with the same key —
     * burning it would make a failed request permanently unrepeatable.
     */
    public static function release(int $clientId, string $key): void
    {
        static::query()
            ->where('api_client_id', $clientId)
            ->where('key', $key)
            ->whereNull('status')
            ->delete();
    }

    public static function complete(int $clientId, string $key, int $status, string $body): void
    {
        static::query()
            ->where('api_client_id', $clientId)
            ->where('key', $key)
            ->update(['status' => $status, 'response' => $body, 'updated_at' => CarbonImmutable::now()]);
    }

    public function isInFlight(): bool
    {
        return $this->response === null;
    }

    /**
     * @return BelongsTo<ApiClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
