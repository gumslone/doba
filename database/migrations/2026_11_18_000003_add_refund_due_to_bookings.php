<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the hotel owes a guest who cancelled inside their free window, and
 * has not paid back yet. Worked out once, at the moment of cancellation —
 * the only moment the answer is not arguable — and counted down by every
 * refund, so "who are we still to refund" is a query and not a memory.
 *
 * And a correction that belongs with it: a stay was counted towards a
 * guest's history the moment it was confirmed, so booking and cancelling
 * made a "returning guest". Stays count when the guest has left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->integer('refund_due')->default(0);
        });

        // A cancelled booking owes the hotel nothing further.
        DB::table('bookings')->where('status', 'cancelled')->update(['balance_due' => 0]);

        foreach (DB::table('guests')->select('id')->cursor() as $guest) {
            $stays = DB::table('bookings')->where('guest_id', $guest->id)->where('status', 'checked_out');

            DB::table('guests')->where('id', $guest->id)->update([
                'stays_count' => (clone $stays)->count(),
                'total_spent' => (int) (clone $stays)->sum('total'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('refund_due');
        });
    }
};
