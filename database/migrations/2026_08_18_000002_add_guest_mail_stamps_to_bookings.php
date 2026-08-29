<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each lifecycle mail went out (§13).
 *
 * A stamp on the booking rather than a log line, because the stamp IS
 * the idempotency: the nightly command selects on NULL, so a command
 * that crashes halfway and reruns cannot mail anybody twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('pre_arrival_sent_at')->nullable();
            $table->timestamp('post_stay_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['pre_arrival_sent_at', 'post_stay_sent_at']);
        });
    }
};
