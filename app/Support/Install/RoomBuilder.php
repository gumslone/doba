<?php

declare(strict_types=1);

namespace App\Support\Install;

use App\Models\RoomType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Turns the wizard's room step into room types and a live calendar (§16).
 *
 * The calendar matters as much as the rooms. A hotelier who finishes the
 * wizard, opens the dashboard and sees an empty calendar concludes the
 * software is broken — so whatever is entered here immediately generates
 * the availability rows that make it look, correctly, like a hotel with
 * rooms for sale.
 */
class RoomBuilder
{
    /**
     * Starting points, so somebody with a twelve-room B&B does not have to
     * describe it from nothing.
     *
     * @var array<string,array<int,array{name:string,units:int,occupancy:int,price:int}>>
     */
    public const TEMPLATES = [
        'bnb' => [
            ['name' => 'Double room', 'units' => 6, 'occupancy' => 2, 'price' => 9000],
            ['name' => 'Single room', 'units' => 3, 'occupancy' => 1, 'price' => 6500],
            ['name' => 'Family room', 'units' => 2, 'occupancy' => 4, 'price' => 13000],
        ],
        'city' => [
            ['name' => 'Standard double', 'units' => 20, 'occupancy' => 2, 'price' => 12000],
            ['name' => 'Twin room', 'units' => 10, 'occupancy' => 2, 'price' => 12000],
            ['name' => 'Junior suite', 'units' => 4, 'occupancy' => 3, 'price' => 21000],
        ],
        'spa' => [
            ['name' => 'Comfort double', 'units' => 12, 'occupancy' => 2, 'price' => 16000],
            ['name' => 'Spa suite', 'units' => 6, 'occupancy' => 2, 'price' => 26000],
            ['name' => 'Family apartment', 'units' => 3, 'occupancy' => 4, 'price' => 30000],
        ],
    ];

    /**
     * @return int how many room types were created
     */
    public function fromTemplate(string $template): int
    {
        return $this->create(self::TEMPLATES[$template] ?? []);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function fromRows(array $rows): int
    {
        $rooms = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;   // an empty row is how somebody skips a blank line
            }

            $rooms[] = [
                'name' => $name,
                'units' => max(1, (int) ($row['units'] ?? 1)),
                'occupancy' => max(1, (int) ($row['occupancy'] ?? 2)),
                // Entered in whole currency, stored in minor units (§5).
                'price' => (int) round((float) ($row['price'] ?? 0) * 100),
            ];
        }

        return $this->create($rooms);
    }

    /**
     * @param  array<int,array{name:string,units:int,occupancy:int,price:int}>  $rooms
     */
    protected function create(array $rooms): int
    {
        if ($rooms === []) {
            return 0;
        }

        $locale = app()->getLocale();
        $created = 0;

        foreach ($rooms as $index => $room) {
            $code = $this->code($room['name']);

            if (RoomType::query()->where('code', $code)->exists()) {
                continue;
            }

            $roomType = RoomType::create([
                'code' => $code,
                'base_occupancy' => min(2, $room['occupancy']),
                'max_occupancy' => $room['occupancy'],
                'default_rate' => $room['price'],
                'total_units' => $room['units'],
                'sort_order' => $index,
                'is_active' => true,
            ]);

            $roomType->translations()->create([
                'locale' => $locale,
                'slug' => Str::slug($room['name']),
                'name' => $room['name'],
            ]);

            $created++;
        }

        if ($created > 0) {
            // The calendar has to be live the moment the wizard ends.
            Artisan::call('availability:extend');
        }

        return $created;
    }

    protected function code(string $name): string
    {
        return mb_substr(Str::upper(Str::slug($name, '_')), 0, 60) ?: 'ROOM';
    }
}
