<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->morphs('mediable');
            $table->string('path');
            $table->string('disk', 32)->default('public');
            $table->json('alt')->nullable();      // per-locale alt text
            // Intrinsic dimensions, stored at upload so every <img> can carry
            // width/height and reserve its space — this is the CLS half of the
            // Core Web Vitals budget in §11, and it cannot be measured at
            // render time without opening the file on every request.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
