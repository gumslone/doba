<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a partner wants to be told things (§17).
 *
 * @property string $url
 * @property string $secret
 * @property array<int,string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 */
class WebhookEndpoint extends Model
{
    /**
     * What can be subscribed to. A partner asks for the events it can
     * handle rather than everything, so a new event type never arrives
     * unannounced at a receiver that will throw on it.
     */
    public const EVENTS = [
        'booking.created',
        'booking.updated',
        'booking.cancelled',
        'payment.succeeded',
        'payment.refunded',
        'availability.changed',
    ];

    /** After this many failures in a row the endpoint is switched off. */
    public const FAILURE_LIMIT = 20;

    protected $fillable = [
        'api_client_id', 'url', 'secret', 'events',
        'is_active', 'consecutive_failures', 'disabled_at',
    ];

    protected $hidden = ['secret'];

    protected $attributes = [
        'is_active' => true,
        'consecutive_failures' => 0,
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'consecutive_failures' => 'integer',
        'disabled_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<ApiClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? [], true);
    }
}
