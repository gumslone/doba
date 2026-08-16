<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 64)->nullable();
            $table->string('locale', 10);
            $table->string('subject')->nullable();
            $table->text('message');
            // Nullable stay dates make the same table serve "request a
            // quote" without a second form (§5).
            $table->date('check_in')->nullable();
            $table->date('check_out')->nullable();
            // string + PHP enum cast, never ENUM — portable subset (§5).
            $table->string('status', 16)->default('new');
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
