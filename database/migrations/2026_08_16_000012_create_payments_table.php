<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);                       // stripe | paypal | manual | bank_transfer
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_customer_id')->nullable();
            $table->string('type', 16);                          // deposit | balance | full | refund
            $table->string('status', 24);                        // string + PHP enum, never ENUM (§5)
            $table->bigInteger('amount');                        // cents; negative never — refunds are their own rows
            $table->string('currency', 3);
            $table->bigInteger('fee')->default(0);
            $table->json('payload')->nullable();                 // raw gateway response, for disputes
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            // One row per gateway object; both engines allow multiple NULLs,
            // so manual payments without a gateway id are unconstrained.
            $table->unique(['gateway', 'gateway_payment_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
