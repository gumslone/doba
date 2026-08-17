<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promo codes (§5, pricing step 7).
     *
     * `usage_count` is a counter, and `promo_code_redemptions` is the
     * ledger that explains it: the counter is what a concurrent claim
     * locks, the ledger is what tells the hotelier which bookings a
     * campaign actually produced. Keeping only the counter makes
     * "how did the newsletter code do?" unanswerable; keeping only the
     * ledger makes every redemption a COUNT under a lock.
     */
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('discount_type', 16);            // percent | fixed | free_nights
            // percent: basis points (1000 = 10%), so no float ever touches
            // the money path. fixed: minor units. free_nights: a count.
            $table->integer('value');

            $table->unsignedTinyInteger('min_nights')->nullable();
            $table->bigInteger('min_total')->nullable();    // minor units

            $table->date('valid_from')->nullable();         // when the code may be USED
            $table->date('valid_to')->nullable();
            $table->date('stay_from')->nullable();          // which stays it applies to
            $table->date('stay_to')->nullable();

            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('per_guest_limit')->nullable();

            $table->json('room_type_ids')->nullable();      // null = every room type
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promo_code_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');                   // minor units, frozen
            $table->timestamp('redeemed_at');
            // Set when the booking is cancelled: the row stays for the
            // campaign report, but a released redemption no longer counts
            // against the usage limit. Deleting it instead would make a
            // code that "ran out" impossible to explain afterwards.
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            // One redemption per booking — a code is applied to the stay,
            // not to each room.
            $table->unique('booking_id');
            $table->index(['promo_code_id', 'released_at']);
        });

        // bookings.promo_code_id was created back with the booking tables
        // as a bare column awaiting this migration. Only the index lands
        // here: adding the constraint would mean rebuilding `bookings` on
        // SQLite, and that table already carries live reservations.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->index('promo_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['promo_code_id']);
        });

        Schema::dropIfExists('promo_code_redemptions');
        Schema::dropIfExists('promo_codes');
    }
};
