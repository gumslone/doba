<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apartments (§5): the same sellable category, with the facts a
 * self-catering unit needs and a one-off fee per stay.
 *
 * A kind rather than a second table: an apartment is booked, priced,
 * held, invoiced and synced exactly like a room. What differs is what
 * the guest is told (bedrooms, bathrooms), a minimum stay the type
 * itself insists on, and a cleaning fee charged once per unit rather
 * than per night — none of which needs its own inventory model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table): void {
            // string + PHP constant, never ENUM (§5)
            $table->string('kind', 16)->default('room')->after('code');
            $table->unsignedTinyInteger('bedrooms')->nullable()->after('bed_setup');
            $table->unsignedTinyInteger('bathrooms')->nullable()->after('bedrooms');
            // Money is integer minor units (§5). Per unit, per stay.
            $table->bigInteger('cleaning_fee')->default(0)->after('default_rate');
            // A floor under the grid's arrival-row min_stay, so a holiday
            // flat that is never let for one night says so on the type
            // itself instead of on 730 availability rows.
            $table->unsignedTinyInteger('min_nights')->default(1)->after('cleaning_fee');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            // Its own column like city_tax: the invoice and the guest
            // summary both show it on its own line.
            $table->bigInteger('cleaning_fee')->default(0)->after('city_tax');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('cleaning_fee');
        });

        Schema::table('room_types', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'bedrooms', 'bathrooms', 'cleaning_fee', 'min_nights']);
        });
    }
};
