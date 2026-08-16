<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 * @property string $name
 * @property string|null $description
 */
class ExtraTranslation extends Translation
{
    protected $fillable = ['extra_id', 'locale', 'name', 'description'];

    /**
     * @return BelongsTo<Extra, $this>
     */
    public function extra(): BelongsTo
    {
        return $this->belongsTo(Extra::class);
    }
}
