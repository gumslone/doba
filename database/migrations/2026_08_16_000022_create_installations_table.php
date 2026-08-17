<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The record that an install happened (§16).
     *
     * One of two markers, deliberately. The other is
     * `storage/installed.lock` on disk, and they fail differently: a
     * deploy that rsyncs storage/ wipes the file, and a database restored
     * from backup carries a row for a filesystem nobody has set up. An
     * install counts as complete only when both agree — when they
     * disagree the wizard opens in repair mode and says what is missing,
     * rather than offering a fresh install that would drop live data.
     */
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table): void {
            $table->id();
            // Which steps are behind us, so a browser crash at step 6
            // resumes at step 6 rather than at the beginning.
            $table->json('steps_completed')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('version', 40)->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
