<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A key can now be claimed before the work it names has finished.
 *
 * The row used to be written only once a booking existed, which left a
 * window between "have I seen this key?" and "here is the answer" — and a
 * partner's retry landing in that window took a second room. Claiming the
 * key first closes it, and a claim needs somewhere to say "still working
 * on it": NULL status and NULL response mean exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('status')->nullable()->change();
            $table->longText('response')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('status')->nullable(false)->change();
            $table->longText('response')->nullable(false)->change();
        });
    }
};
