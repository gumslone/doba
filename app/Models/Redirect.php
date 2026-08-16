<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Essential when a hotel migrates from an old site (§5): every URL that had
 * rankings and inbound links must land somewhere, or the new site launches
 * by throwing away the only SEO the hotel already had.
 *
 * @property string $from
 * @property string $to
 * @property int $code
 */
class Redirect extends Model
{
    protected $fillable = ['from', 'to', 'code', 'hits', 'is_active'];

    protected $casts = [
        'code' => 'integer',
        'hits' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Normalise a path the way the lookup expects: leading slash, no
     * trailing slash, no query string. "/spa/" and "spa" are one row.
     */
    public static function normalise(string $path): string
    {
        $path = '/'.trim(parse_url($path, PHP_URL_PATH) ?: $path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
