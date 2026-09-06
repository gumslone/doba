<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second factor for the admin session (§14).
 *
 * The secret and the recovery codes are stored encrypted at rest — a
 * database backup that leaks must not hand over the one thing that
 * makes a stolen password insufficient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('totp_secret')->nullable()->after('password');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_secret');
            $table->text('totp_recovery_codes')->nullable()->after('totp_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_recovery_codes']);
        });
    }
};
