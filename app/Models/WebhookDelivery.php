<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt at one delivery. Every attempt lands here, not just the
 * last: "it worked on the fourth try" and "it worked first time" are
 * different facts about a partner's endpoint.
 */
class WebhookDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'webhook_endpoint_id', 'event_id', 'event',
        'attempt', 'status', 'error', 'duration_ms', 'created_at',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'status' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
