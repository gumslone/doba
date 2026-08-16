<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsDateString;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $priority
 */
class Season extends Model
{
    protected $fillable = ['name', 'starts_on', 'ends_on', 'priority'];

    protected $casts = [
        'starts_on' => AsDateString::class,
        'ends_on' => AsDateString::class,
        'priority' => 'integer',
    ];

    /**
     * @return HasMany<SeasonRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(SeasonRate::class);
    }

    public function containsDate(CarbonInterface $date): bool
    {
        // Compared as Y-m-d strings — a night is a night, not an instant,
        // and string comparison is the one thing both engines and PHP agree
        // on for plain dates (§5, §20 risk 4).
        $day = $date->toDateString();

        return $this->starts_on->toDateString() <= $day
            && $day <= $this->ends_on->toDateString();
    }
}
