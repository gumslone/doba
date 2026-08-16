<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            // standard | non_refundable | early_bird | long_stay | package
            $table->string('type', 32)->default('standard');

            // How the plan moves the resolved nightly price (§7 step 4).
            $table->string('adjustment_type', 16)->default('percent'); // percent | fixed
            $table->integer('adjustment_value')->default(0);           // basis points, or cents when fixed

            // Eligibility. Null means "no bound".
            $table->unsignedTinyInteger('min_nights')->nullable();
            $table->unsignedTinyInteger('max_nights')->nullable();
            $table->unsignedSmallInteger('min_days_before_arrival')->nullable();
            $table->unsignedSmallInteger('max_days_before_arrival')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->boolean('includes_breakfast')->default(false);

            // The cancellation terms this plan sells under. `refundable`
            // false is the non-refundable saver rate; cancellation_hours is
            // the free-cancellation window before arrival.
            $table->boolean('refundable')->default(true);
            $table->unsignedSmallInteger('cancellation_hours')->default(48);

            $table->boolean('is_active')->default(true);
            // Highest priority wins when two plans would both be cheapest;
            // also the display order on the room page.
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('rate_plan_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            // The exact wording the guest agrees to. Snapshotted onto
            // booking_rooms at booking time and never read from here
            // again, because a later edit must not change what a taken
            // booking agreed to (§7).
            $table->text('policy_text')->nullable();
            $table->timestamps();

            $table->unique(['rate_plan_id', 'locale']);
        });

        // Which plans apply to which room types. Empty = every room type,
        // so a hotel with one house-wide rate configures nothing.
        Schema::create('rate_plan_room_type', function (Blueprint $table): void {
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();

            $table->unique(['rate_plan_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_room_type');
        Schema::dropIfExists('rate_plan_translations');
        Schema::dropIfExists('rate_plans');
    }
};
