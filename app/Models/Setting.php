<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Hotel\HotelSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * @property string $group
 * @property string $key
 * @property mixed $value
 * @property bool $is_translatable
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'is_translatable'];

    protected $casts = [
        'value' => 'array',
        'is_translatable' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(static fn () => HotelSettings::flush());
        static::deleted(static fn () => HotelSettings::flush());
    }

    public static function tableExists(): bool
    {
        return Schema::hasTable((new self)->getTable());
    }

    public static function put(string $group, string $key, mixed $value, bool $translatable = false): self
    {
        return static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value, 'is_translatable' => $translatable],
        );
    }
}
