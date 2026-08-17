<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A course or a part of the card: starters, mains, desserts, the wine
 * list, the cocktails.
 *
 * @property string $code
 * @property bool $is_active
 */
class MenuSection extends Model
{
    use HasTranslations;

    protected string $translationModel = MenuSectionTranslation::class;

    protected string $translationForeignKey = 'menu_section_id';

    protected $fillable = ['venue_id', 'code', 'sort_order', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return HasMany<Dish, $this>
     */
    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class)->orderBy('sort_order')->orderBy('id');
    }
}
