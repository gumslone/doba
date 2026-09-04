<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The part of discount_total that a returning guest earned (§7, phase 7).
 *
 * Mirrored rather than replacing discount_total: every total, invoice
 * line and report already sums discount_total, and a second column that
 * only SAYS where the discount came from lets all of that arithmetic
 * stay exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('loyalty_discount')->default(0)->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('loyalty_discount');
        });
    }
};
