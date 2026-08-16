<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table): void {
            $table->id();
            // Stored lowercased; the unique index is what makes "repeat
            // guests build a history" (§5) hold under concurrency.
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 64)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('locale', 10)->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('stays_count')->default(0);
            $table->bigInteger('total_spent')->default(0);      // cents
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();          // human-readable, e.g. ALP-2026-0412
            $table->string('manage_token', 40);                 // guest self-service link, no login
            $table->string('status', 16);                       // string + PHP enum, never ENUM (§5)
            $table->string('source', 32)->default('direct');
            $table->string('channel_reference')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('nights');
            $table->unsignedTinyInteger('adults');
            $table->unsignedTinyInteger('children')->default(0);
            $table->json('children_ages')->nullable();
            $table->string('currency', 3);
            // Money: integer minor units throughout (§5).
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('extras_total')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('city_tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('deposit_due')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('balance_due')->default(0);
            // FK lands with the promo_codes table in phase 3.
            $table->unsignedBigInteger('promo_code_id')->nullable();
            $table->string('locale', 10);                       // all later guest mail uses it
            $table->foreignId('guest_id')->constrained();
            $table->text('guest_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('check_in');
        });

        Schema::create('booking_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained();
            // FKs land with their tables (rate_plans: phase 3, rooms: phase 2 option).
            $table->unsignedBigInteger('rate_plan_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedTinyInteger('adults');
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('guest_name')->nullable();
            $table->bigInteger('price_total');                  // cents, this room's nights
            // Frozen in the guest's language at booking time; a dispute is
            // settled by the wording the guest agreed to, not today's (§7).
            $table->text('cancellation_policy_snapshot')->nullable();
            $table->unsignedSmallInteger('cancellation_hours_snapshot')->nullable();
            $table->boolean('refundable_snapshot')->default(true);
            $table->timestamps();
        });

        Schema::create('booking_room_nights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_room_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('price');                        // cents — never recompute a past booking (§5)
            $table->timestamps();

            $table->unique(['booking_room_id', 'date']);
        });

        Schema::create('booking_holds', function (Blueprint $table): void {
            $table->id();
            // Without the FK the release command cannot find the pending
            // booking it must cancel (§5).
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->nullable();
            $table->foreignId('room_type_id')->constrained();
            $table->date('date');
            $table->unsignedSmallInteger('units');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        Schema::create('booking_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();      // null = created
            $table->string('to_status', 16);
            $table->foreignId('user_id')->nullable()->constrained(); // null = system/guest
            $table->string('reason')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_history');
        Schema::dropIfExists('booking_holds');
        Schema::dropIfExists('booking_room_nights');
        Schema::dropIfExists('booking_rooms');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('guests');
    }
};
