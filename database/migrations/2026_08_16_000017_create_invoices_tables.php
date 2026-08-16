<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            // restrictOnDelete, never cascade: once a number is issued the
            // document is a tax record, and deleting the stay it documents
            // must not silently erase it or punch a hole in the sequence.
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            // Sequential per year per install, e.g. 2026-0001. Unique
            // because a duplicate invoice number is a tax problem, not a
            // display bug.
            $table->string('number', 32)->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->timestamp('issued_at');
            $table->string('pdf_path')->nullable();
            $table->string('currency', 3);
            // Money in minor units (§5). net + tax === gross, always.
            $table->bigInteger('net_total');
            $table->bigInteger('tax_total');
            $table->bigInteger('gross_total');
            // The billing address as it stood when the invoice was issued —
            // a guest moving house must not rewrite last year's invoice.
            $table->json('billed_to')->nullable();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedSmallInteger('quantity')->default(1);
            // A single tax_total is not enough: DE/PL/UA invoices must show
            // a per-VAT-rate breakdown, and accommodation is typically a
            // reduced rate while breakfast, parking and spa are standard (§5).
            $table->unsignedSmallInteger('tax_rate');   // basis points, 700 = 7%
            $table->bigInteger('unit_net');
            $table->bigInteger('line_net');
            $table->bigInteger('tax_amount');
            $table->bigInteger('line_gross');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
