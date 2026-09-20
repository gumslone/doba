<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift vouchers (§8): money received in advance, spent later against a
 * booking. A voucher is a means of payment, not a discount — it never
 * touches a price or an invoice line; redeeming one records a payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            // Money is integer minor units, never DECIMAL (§5).
            $table->bigInteger('initial_amount');
            $table->bigInteger('balance');
            $table->string('currency', 3);
            // string + PHP constant, never ENUM (§5)
            $table->string('status', 16)->default('pending');
            $table->string('sold_via', 16)->default('web');     // web | desk
            $table->string('buyer_name');
            $table->string('buyer_email')->nullable();
            $table->string('recipient_name')->nullable();
            $table->text('message')->nullable();
            $table->string('locale', 10);
            $table->text('internal_note')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('gift_voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gift_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            // Set when the payment was refunded and the money went back on
            // the voucher; the row stays, because it happened.
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_voucher_redemptions');
        Schema::dropIfExists('gift_vouchers');
    }
};
