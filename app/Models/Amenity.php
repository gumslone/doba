<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Wifi, balcony, minibar, … (§5). Not routable — amenities render on room
 * pages and feed the LocationFeatureSpecification entries in the room's
 * JSON-LD.
 *
 * @property int $id
 * @property string|null $icon
 */
class Amenity extends Model
{
    use HasTranslations;

    protected string $translationModel = AmenityTranslation::class;

    protected string $translationForeignKey = 'amenity_id';

    protected $fillable = ['icon', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    /**
     * @return BelongsToMany<RoomType, $this>
     */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class);
    }
}
