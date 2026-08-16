<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 * @property string $slug
 * @property string $name
 */
class RoomTypeTranslation extends Translation
{
    protected $fillable = [
        'room_type_id', 'locale', 'slug', 'name',
        'short_description', 'description', 'meta_title', 'meta_description',
    ];

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
