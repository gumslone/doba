<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Anything photos can be attached to.
 *
 * Deliberately only the relation: callers type against `Model&HasMedia`,
 * so everything Eloquent already provides (getKey, ::class, saving) comes
 * from the Model side and is not redeclared here — redeclaring `getKey()`
 * with a return type is incompatible with the framework's untyped one and
 * fatals at class-load.
 *
 * @phpstan-require-extends Model
 */
interface HasMedia
{
    /**
     * Covariant in the declaring model: each implementation narrows this to
     * its own class, which `$this` cannot express here because the
     * interface itself is not a Model.
     *
     * @return MorphMany<Media, covariant Model>
     */
    public function media(): MorphMany;
}
