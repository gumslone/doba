<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extras', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->bigInteger('price');                          // cents (§5)
            // How the price multiplies: stay | night | person | person_night.
            // Breakfast is per person-night, a transfer is per stay, a cot is
            // per night — getting this wrong is a pricing bug the guest sees
            // on the invoice.
            $table->string('applies_per', 16)->default('stay');
            // Accommodation is usually a reduced VAT rate while breakfast,
            // parking and spa are standard — the invoice needs a per-rate
            // breakdown, so the rate lives on the extra (§5).
            $table->unsignedSmallInteger('tax_rate')->default(0);  // basis points, 1900 = 19%
            $table->string('icon', 64)->nullable();
            $table->unsignedSmallInteger('max_quantity')->default(1);
            $table->boolean('is_active')->default(true);
            // An extra every guest gets anyway (a welcome drink) is shown as
            // included rather than offered for sale.
            $table->boolean('is_included')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('extra_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('extra_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['extra_id', 'locale']);
        });

        // Which extras a given room type offers. Empty = offered with every
        // room, so a hotel that sells breakfast house-wide configures nothing.
        Schema::create('extra_room_type', function (Blueprint $table): void {
            $table->foreignId('extra_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();

            $table->unique(['extra_id', 'room_type_id']);
        });

        Schema::create('booking_extras', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained();
            $table->unsignedSmallInteger('quantity')->default(1);
            // Snapshotted like every other price: an extra's price may change,
            // a taken booking's may not (§7).
            $table->bigInteger('unit_price');
            $table->bigInteger('total');
            $table->string('applies_per', 16);
            $table->unsignedSmallInteger('tax_rate')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extras');
        Schema::dropIfExists('extra_room_type');
        Schema::dropIfExists('extra_translations');
        Schema::dropIfExists('extras');
    }
};
