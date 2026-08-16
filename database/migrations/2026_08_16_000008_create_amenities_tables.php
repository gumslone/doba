<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table): void {
            $table->id();
            $table->string('icon', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('amenity_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->timestamps();

            $table->unique(['amenity_id', 'locale']);
        });

        Schema::create('amenity_room_type', function (Blueprint $table): void {
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();

            $table->unique(['amenity_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_room_type');
        Schema::dropIfExists('amenity_translations');
        Schema::dropIfExists('amenities');
    }
};
