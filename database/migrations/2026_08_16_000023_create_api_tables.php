<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The inbound partner API (§17).
     */
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // The public half, sent as X-Api-Key-Id. Indexed because every
            // request looks a client up by it.
            $table->string('key_id', 32)->unique();
            // The secret half, hashed. Shown once at creation and never
            // recoverable: a key a hotelier can read back out of the admin
            // is a key that leaks with the database.
            $table->string('secret_hash');
            $table->json('scopes');
            // Empty means "from anywhere". A partner who can name their
            // egress IPs should.
            $table->json('ip_allowlist')->nullable();
            // Sandbox keys run the same code against test bookings that
            // never touch real inventory or send real mail. Without one,
            // every partner's first integration test is against the
            // hotel's live calendar.
            $table->boolean('sandbox')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['revoked_at', 'expires_at']);
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            // The request this key was first used for. A key replayed with
            // a DIFFERENT body is a bug in the caller, not a retry, and
            // answering it with the old response would hide that.
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status');
            // The raw response body, not a json column. MySQL's JSON type
            // reorders object keys, so replaying through it would return
            // the same data in a different shape — and "identical" is the
            // whole promise of an idempotent retry.
            $table->longText('response');
            $table->timestamps();

            $table->unique(['api_client_id', 'key']);
            $table->index('created_at');
        });

        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id');
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            // A partner's bug report arrives as a request id and nothing
            // else, so that is what this is indexed on.
            $table->index('request_id');
            $table->index(['api_client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_clients');
    }
};
