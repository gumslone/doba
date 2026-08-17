<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The times a front desk actually works with.
     *
     * `check_in` and `check_out` are dates — the nights sold (§6) — and
     * must stay that way, because inventory is counted in nights. These
     * are the clock times around them, which are operational rather than
     * commercial: when the guest says they will arrive, when they are
     * actually due to leave, and when they in fact came and went.
     *
     * Stored as plain strings rather than times: they are wall-clock at
     * the property, never instants. A hotel that puts "14:00" on a booking
     * means two in the afternoon there, whatever the server thinks its
     * timezone is.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // What the guest told us at checkout. An estimate, and treated
            // as one — a late arrival is a room held, not a room resold.
            $table->string('arrival_time', 5)->nullable()->after('nights');

            // What the guest asked for, and what the hotel agreed to. Two
            // columns because late checkout is "subject to availability on
            // the day": a request is not an answer, and showing the two as
            // one field is how a guest gets told yes by a form.
            $table->string('requested_checkout_time', 5)->nullable()->after('arrival_time');
            $table->string('checkout_time', 5)->nullable()->after('requested_checkout_time');

            // When it actually happened. Derivable from the status history,
            // but the desk view asks "who is in the house right now" on
            // every page load and should not join to find out.
            $table->timestamp('checked_in_at')->nullable()->after('confirmed_at');
            $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'arrival_time',
                'requested_checkout_time',
                'checkout_time',
                'checked_in_at',
                'checked_out_at',
            ]);
        });
    }
};
