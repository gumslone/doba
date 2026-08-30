<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical rooms (§5, phase 2): the doors the front desk actually opens.
 *
 * Availability never reads this table — the engine sells CATEGORIES
 * against `allotment`, and these rows exist so a category, once sold,
 * can be pinned to a door. Taking a room out of service is done by
 * lowering the allotment on the grid; `out_of_order` here only stops
 * the desk assigning the door, it does not stop the site selling the
 * category. Two jobs, two switches, on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            // A string, because hotels have rooms called 101a, A2 and
            // "Turmzimmer" — and unique across the house, not the type,
            // because the desk says a number, never a type + number.
            $table->string('number', 32)->unique();
            $table->string('floor', 32)->nullable();
            $table->string('status', 16)->default('clean');
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('booking_rooms', function (Blueprint $table): void {
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table): void {
            $table->dropForeign(['room_id']);
        });

        Schema::dropIfExists('rooms');
    }
};
