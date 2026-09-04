<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified guest reviews (§5 phase 6, FEATURE_REVIEWS).
 *
 * One review per BOOKING, never per visitor: the thing that makes these
 * worth showing is that every single one belongs to a stay that actually
 * happened here. An OTA can scrape stars from anywhere; a direct-booking
 * site's reviews are credible precisely because they cannot be written
 * by anyone who did not sleep in the bed.
 *
 * The hotel's response lives on the row rather than in the sketched
 * review_responses table: a review gets at most one public reply, and a
 * table for a to-one that will never become to-many is a join nobody
 * needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body');
            $table->string('locale', 5);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->text('hotel_response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
