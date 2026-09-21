<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online check-in (§12): the registration form, filled in before arrival.
 *
 * One row per booking. The party's details — dates of birth, document
 * numbers — are the most sensitive thing this system holds, so they live
 * in a single encrypted column rather than spread over queryable ones:
 * nobody needs to search by passport number, and a leaked database dump
 * should not contain any.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('party');                      // encrypted JSON
            $table->timestamp('submitted_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_registrations');
    }
};
