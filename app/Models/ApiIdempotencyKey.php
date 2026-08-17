<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remembered response, so a retried request does not book a second room.
 *
 * @property string $key
 * @property string $request_hash
 * @property int $status
 * @property string $response
 */
class ApiIdempotencyKey extends Model
{
    protected $fillable = ['api_client_id', 'key', 'request_hash', 'status', 'response'];

    protected $casts = [
        'status' => 'integer',
    ];

    /**
     * @return BelongsTo<ApiClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
