<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Twenty ungrouped ticks are a wall a guest skims past; the same twenty
     * under "Bathroom / Comfort / View" answer the question they actually
     * came with ("is there a shower or a bath?").
     *
     * NOTE for future migrations on `availability`: this table has no CHECK
     * constraint, so a plain add-column is safe here. The §5 warning about
     * ->change() dropping constraints on SQLite applies there, not here.
     */
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table): void {
            $table->string('category', 32)->default('general')->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
