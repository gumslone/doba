<?php

declare(strict_types=1);

use App\Models\Availability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'default_rate' => 10000,
        'total_units' => 3,
    ]);
});

/*
|--------------------------------------------------------------------------
| The CHECK constraint (§5)
|--------------------------------------------------------------------------
| Asserted by inserting a violating row, not by inspecting DDL: MySQL
| before 8.0.16 parses CHECK and ignores it, and a later ->change() on
| SQLite rebuilds the table and silently drops it. Only a refused write
| proves the net is still there — on whichever engine CI is running.
*/

it('refuses counters that exceed the allotment', function (): void {
    Availability::create([
        'room_type_id' => $this->roomType->id,
        'date' => '2027-06-01',
        'allotment' => 3,
        'booked' => 2,
        'held' => 2, // 2 + 2 > 3
    ]);
})->throws(QueryException::class);

it('refuses negative counters', function (): void {
    Availability::create([
        'room_type_id' => $this->roomType->id,
        'date' => '2027-06-01',
        'allotment' => 3,
        'booked' => -1,
    ]);
})->throws(QueryException::class);

it('refuses an increment that would oversell, even bypassing the model', function (): void {
    Availability::create([
        'room_type_id' => $this->roomType->id,
        'date' => '2027-06-01',
        'allotment' => 3,
        'booked' => 3,
    ]);

    // The exact statement the booking transaction runs (§6).
    expect(fn () => DB::table('availability')
        ->where('room_type_id', $this->roomType->id)
        ->increment('held'))->toThrow(QueryException::class);
});

it('enforces one row per room type per date', function (): void {
    Availability::create(['room_type_id' => $this->roomType->id, 'date' => '2027-06-01', 'allotment' => 3]);

    expect(fn () => Availability::create([
        'room_type_id' => $this->roomType->id, 'date' => '2027-06-01', 'allotment' => 3,
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| availability:extend (§5)
|--------------------------------------------------------------------------
*/

it('pre-generates rows through the window plus the checkout boundary', function (): void {
    config()->set('doba.booking.booking_window_days', 30);
    config()->set('doba.booking.max_nights', 5);

    Artisan::call('availability:extend');

    // 30 + 5 + 1 days ahead, inclusive of today ⇒ 37 rows: a stay starting
    // on the last bookable day still needs its nights AND its checkout row.
    expect(Availability::query()->where('room_type_id', $this->roomType->id)->count())->toBe(37)
        ->and(Availability::query()->orderByDesc('date')->first()->date->toDateString())
        ->toBe(CarbonImmutable::today()->addDays(36)->toDateString());

    expect(Availability::query()->first())
        ->allotment->toBe(3)
        ->booked->toBe(0);
});

it('is idempotent and never touches an edited row', function (): void {
    config()->set('doba.booking.booking_window_days', 10);
    config()->set('doba.booking.max_nights', 2);

    Artisan::call('availability:extend');

    $edited = Availability::query()->orderBy('date')->skip(3)->first();
    $edited->update(['allotment' => 1, 'closed' => true, 'price' => 9900]);

    Artisan::call('availability:extend');

    expect(Availability::query()->where('room_type_id', $this->roomType->id)->count())->toBe(14)
        ->and($edited->refresh())
        ->allotment->toBe(1)
        ->closed->toBeTrue()
        ->price->toBe(9900);
});

it('skips inactive room types', function (): void {
    $this->roomType->update(['is_active' => false]);

    Artisan::call('availability:extend');

    expect(Availability::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| BEGIN IMMEDIATE on SQLite (§6 — mandatory, not tuning)
|--------------------------------------------------------------------------
*/

it('opens SQLite transactions as a writer from the first statement', function (): void {
    // A file-backed database on a second named connection: :memory: cannot
    // be shared between two connections, and sharing is the whole point —
    // the test proves the write lock is held BEFORE any write happens.
    $path = storage_path('framework/testing/immediate-'.getmypid().'.sqlite');
    @unlink($path);
    touch($path);

    config()->set('database.connections.sqlite_immediate', array_merge(
        config('database.connections.sqlite'),
        ['database' => $path, 'url' => null]
    ));

    $connection = DB::connection('sqlite_immediate');

    try {
        Schema::connection('sqlite_immediate')->create('t', function ($table): void {
            $table->id();
        });

        expect($connection->selectOne('pragma journal_mode')->journal_mode)->toBe('wal');

        $connection->beginTransaction();

        // No write has been issued inside the transaction yet. Under
        // Laravel's default deferred BEGIN this insert from a second,
        // independent connection would succeed — and the booking
        // transaction's later upgrade would be the thing that fails,
        // sporadically, in production. IMMEDIATE means the lock already
        // exists, so the outsider is refused instantly.
        $outsider = new PDO('sqlite:'.$path);
        $outsider->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $outsider->exec('pragma busy_timeout = 150');

        expect(fn () => $outsider->exec('insert into t default values'))
            ->toThrow(PDOException::class, 'database is locked');

        $connection->rollBack();

        // With the transaction gone the same outsider write goes through.
        $outsider->exec('insert into t default values');
        expect((int) $outsider->query('select count(*) from t')->fetchColumn())->toBe(1);

        unset($outsider);
    } finally {
        $connection->disconnect();
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }
});
