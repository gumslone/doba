<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 * @property string $name
 * @property string|null $policy_text
 */
class RatePlanTranslation extends Translation
{
    protected $fillable = ['rate_plan_id', 'locale', 'name', 'description', 'policy_text'];

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }
}
