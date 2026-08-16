<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The heart of the system (§5): one row per room type per night drives
     * the calendar, the price and every restriction.
     *
     * The CHECK constraint is the last line of defence against overselling
     * and needs raw DDL on both engines: Laravel's schema builder has no
     * CHECK API, and SQLite has no ALTER TABLE ADD CONSTRAINT, so there the
     * whole table is created raw with the constraint inline. Any later
     * migration that uses ->change() on this table rebuilds it and silently
     * drops the constraint — re-emit it there, and keep the test that
     * proves it still bites.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                create table "availability" (
                    "id" integer primary key autoincrement not null,
                    "room_type_id" integer not null,
                    "date" date not null,
                    "allotment" integer not null default 0,
                    "booked" integer not null default 0,
                    "held" integer not null default 0,
                    "price" integer,
                    "min_stay" integer not null default 1,
                    "max_stay" integer,
                    "min_stay_through" integer,
                    "closed" tinyint(1) not null default 0,
                    "closed_to_arrival" tinyint(1) not null default 0,
                    "closed_to_departure" tinyint(1) not null default 0,
                    "created_at" datetime,
                    "updated_at" datetime,
                    foreign key("room_type_id") references "room_types"("id") on delete cascade,
                    constraint "chk_availability_counters"
                        check ("booked" >= 0 and "held" >= 0 and "booked" + "held" <= "allotment")
                )
                SQL);
        } else {
            Schema::create('availability', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
                $table->date('date');
                $table->unsignedSmallInteger('allotment')->default(0);
                $table->unsignedSmallInteger('booked')->default(0);
                $table->unsignedSmallInteger('held')->default(0);
                $table->bigInteger('price')->nullable();           // cents; overrides the season/default rate
                $table->unsignedTinyInteger('min_stay')->default(1);
                $table->unsignedTinyInteger('max_stay')->nullable();
                $table->unsignedTinyInteger('min_stay_through')->nullable();
                $table->boolean('closed')->default(false);
                $table->boolean('closed_to_arrival')->default(false);
                $table->boolean('closed_to_departure')->default(false);
                $table->timestamps();
            });

            // Enforced from MySQL 8.0.16; earlier versions parse and ignore
            // it, which is why the test suite inserts a violating row rather
            // than trusting the DDL.
            DB::statement(
                'alter table `availability` add constraint `chk_availability_counters` '
                .'check (`booked` >= 0 and `held` >= 0 and `booked` + `held` <= `allotment`)'
            );
        }

        Schema::table('availability', function (Blueprint $table): void {
            $table->unique(['room_type_id', 'date']);
            $table->index('date');
        });

        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('season_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            // Mon=bit 0 … Sun=bit 6; 127 = every day. "Saturdays in July"
            // is one row, not thirty-one availability edits (§5).
            $table->unsignedTinyInteger('weekday_mask')->default(127);
            $table->bigInteger('price');                            // cents
            $table->timestamps();

            $table->unique(['season_id', 'room_type_id', 'weekday_mask']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_rates');
        Schema::dropIfExists('seasons');
        Schema::dropIfExists('availability');
    }
};
