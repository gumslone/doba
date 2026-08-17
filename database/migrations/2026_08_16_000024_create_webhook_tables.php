<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbound webhooks (§17), so partners do not have to poll.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            // Each endpoint signs with its own secret, so revoking one
            // partner's does not invalidate everybody else's deliveries.
            $table->string('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            // Carried in the payload as well: a receiver must be able to
            // recognise a redelivery it has already processed, because
            // delivery is at-least-once and can arrive out of order.
            $table->uuid('event_id');
            $table->string('event', 64);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
