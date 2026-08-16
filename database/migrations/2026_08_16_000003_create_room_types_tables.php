<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();   // internal, stable, non-routing
            $table->unsignedTinyInteger('base_occupancy')->default(2);
            $table->unsignedTinyInteger('max_occupancy')->default(2);
            $table->unsignedTinyInteger('max_adults')->default(2);
            $table->unsignedTinyInteger('max_children')->default(0);
            // Money is integer minor units, never DECIMAL (§5).
            $table->bigInteger('extra_adult_price')->default(0);
            $table->bigInteger('extra_child_price')->default(0);
            $table->unsignedSmallInteger('size_sqm')->nullable();
            $table->string('bed_setup')->nullable();
            $table->bigInteger('default_rate')->nullable();
            $table->unsignedSmallInteger('total_units')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('room_type_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            // The routable slug lives here, not on the parent: /de/zimmer/…
            // and /en/rooms/… must resolve to the same room type (§5).
            $table->string('slug');
            $table->string('name');
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestamps();

            $table->unique(['room_type_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_translations');
        Schema::dropIfExists('room_types');
    }
};
