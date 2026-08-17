<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restaurant, bar and café, with their menus.
     *
     * `venues` rather than `restaurants` because a hotel almost never has
     * exactly one: there is a restaurant, a bar, and often a café or a
     * terrace, and they differ only in type, hours and menu. One table
     * with a type column beats three that drift apart.
     *
     * Prices live on the dish in minor units like every other amount
     * (§5), and are nullable — "market price" is a real menu entry, and a
     * nullable column says so honestly instead of printing €0.00.
     */
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('type', 24)->default('restaurant');   // restaurant | bar | cafe | lounge
            $table->string('phone', 64)->nullable();
            $table->string('price_range', 8)->nullable();        // schema.org's "€€"
            $table->unsignedSmallInteger('seats')->nullable();
            // Per weekday, as {"mon": [["12:00","14:30"],["18:00","22:00"]]}:
            // a kitchen that closes between lunch and dinner is the normal
            // case, so one open/close pair per day would be wrong from the
            // first hotel that used it.
            $table->json('opening_hours')->nullable();
            $table->boolean('reservations')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('venue_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('slug');
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestamps();

            // Slugs are unique per locale and never fall back (§10): an
            // untranslated venue disappears from that language entirely
            // rather than showing a German slug on the French site.
            $table->unique(['locale', 'slug']);
            $table->unique(['venue_id', 'locale']);
        });

        Schema::create('menu_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['venue_id', 'code']);
        });

        Schema::create('menu_section_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_section_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['menu_section_id', 'locale']);
        });

        Schema::create('dishes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_section_id')->constrained()->cascadeOnDelete();
            // Null means "market price" or "on request" — printing €0.00
            // for the day's catch is worse than printing nothing.
            $table->bigInteger('price')->nullable();
            $table->string('unit', 32)->nullable();               // "0.5 l", "100 g", "per person"
            // EU 1169/2011: the 14 declarable allergens. A restaurant that
            // publishes a menu online publishes these with it, and a guest
            // with a nut allergy should not have to telephone.
            $table->json('allergens')->nullable();
            $table->json('diets')->nullable();                    // vegetarian, vegan, gluten_free, …
            $table->boolean('is_available')->default(true);
            $table->boolean('is_signature')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('dish_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['dish_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_translations');
        Schema::dropIfExists('dishes');
        Schema::dropIfExists('menu_section_translations');
        Schema::dropIfExists('menu_sections');
        Schema::dropIfExists('venue_translations');
        Schema::dropIfExists('venues');
    }
};
