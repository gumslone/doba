<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Just a named container (§5) — the images themselves are media
        // rows attached polymorphically. One media table for the whole
        // system, or image handling gets written twice.
        Schema::create('galleries', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gallery_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->timestamps();

            $table->unique(['gallery_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_translations');
        Schema::dropIfExists('galleries');
    }
};
