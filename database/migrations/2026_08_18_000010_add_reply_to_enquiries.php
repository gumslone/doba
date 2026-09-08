<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The enquiries inbox (§12): what was answered, and when.
 *
 * `replied_by` existed from the start; the reply itself was sent from
 * the hotelier's own mail client and never recorded. Now that the
 * answer is written in the panel, it is kept — "what did we tell them"
 * is the question the desk asks when the guest rings back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->text('reply')->nullable()->after('message');
            $table->timestamp('replied_at')->nullable()->after('replied_by');
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->dropColumn(['reply', 'replied_at']);
        });
    }
};
