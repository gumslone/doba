<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Tier-1 iCal channel sync (§9).
     *
     * Deliberately NO new counter on `availability`: an OTA block occupies
     * the room exactly as a direct booking does, so it increments the same
     * `booked` column and lives under the same CHECK constraint. A third
     * counter would mean rebuilding that table on SQLite and re-emitting
     * the constraint — new ways to oversell, in the table whose whole job
     * is to make overselling impossible. `channel_bookings` is the ledger
     * that says which part of `booked` came from where.
     */
    public function up(): void
    {
        Schema::create('channel_feeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);                  // booking_com, airbnb, vrbo, other
            $table->string('name');
            $table->text('import_url')->nullable();         // null = export-only mapping
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            // The plausibility baseline: a feed that drops from 40 events
            // to 2 is a truncated response, not forty cancellations.
            $table->unsignedInteger('last_event_count')->nullable();
            $table->unsignedInteger('consecutive_error_count')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'last_synced_at']);
        });

        Schema::create('channel_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_feed_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            // The OTA's own identifier. Everything is matched on this, so a
            // re-import of the same feed changes nothing.
            $table->string('external_uid');
            $table->string('summary')->nullable();
            $table->date('check_in');
            $table->date('check_out');                      // exclusive, as DTEND already is
            $table->unsignedSmallInteger('units')->default(1);

            // The removal guard (§9). An event that vanishes is not gone
            // until it has been absent from three consecutive good syncs.
            $table->timestamp('missing_since')->nullable();
            $table->unsignedSmallInteger('missing_syncs')->default(0);
            // A near stay is never auto-released: releasing a room late
            // costs one night, releasing it wrongly costs a guest standing
            // at the desk with a reservation nobody can honour.
            $table->boolean('needs_review')->default(false);
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->unique(['channel_feed_id', 'external_uid']);
            $table->index(['room_type_id', 'check_in']);
        });

        Schema::table('room_types', function (Blueprint $table): void {
            // The export URL's only credential, so it is a full-length
            // random token rather than the slug or the id.
            $table->string('ical_token', 40)->nullable()->unique();
        });

        foreach (DB::table('room_types')->pluck('id') as $id) {
            DB::table('room_types')->where('id', $id)->update(['ical_token' => Str::random(40)]);
        }
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table): void {
            $table->dropColumn('ical_token');
        });

        Schema::dropIfExists('channel_bookings');
        Schema::dropIfExists('channel_feeds');
    }
};
