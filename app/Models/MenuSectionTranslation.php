<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 * @property string $name
 */
class MenuSectionTranslation extends Translation
{
    protected $fillable = ['menu_section_id', 'locale', 'name', 'description'];

    /**
     * @return BelongsTo<MenuSection, $this>
     */
    public function menuSection(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class);
    }
}
