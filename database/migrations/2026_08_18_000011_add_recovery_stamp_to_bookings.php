<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unfinished-booking reminder (§13) is sent once or never: the stamp
 * is what makes "once" true across scheduler runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('recovery_sent_at')->nullable()->after('post_stay_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('recovery_sent_at');
        });
    }
};
