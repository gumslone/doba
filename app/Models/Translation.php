<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for every *_translations row.
 *
 * It exists so the HasTranslations trait can name a concrete related type:
 * without it, `hasMany($this->translationModel)` is a `hasMany(string)` that
 * no static analysis can resolve, and every `$translation->slug` becomes an
 * undefined-property warning that hides the real ones.
 *
 * @property string $locale
 * @property string $slug
 */
abstract class Translation extends Model {}
