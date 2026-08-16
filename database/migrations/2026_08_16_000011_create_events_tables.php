<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // Defaults to the hotel itself when empty — most hotel events
            // (wine tastings, live music, seasonal dinners) happen in-house.
            $table->string('location')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index('starts_at');
        });

        Schema::create('event_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('slug');
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_translations');
        Schema::dropIfExists('events');
    }
};
