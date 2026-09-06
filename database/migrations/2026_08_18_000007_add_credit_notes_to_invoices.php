<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes live in the invoices table (§8).
 *
 * A credit note IS an invoice — same numbering sequence, same lines
 * model, same VAT breakdown — with every amount negated and a pointer
 * to the document it reverses. Keeping it in the same table keeps the
 * sequence gapless, which is the one property a tax audit checks first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('kind', 16)->default('invoice')->after('booking_id');
            $table->foreignId('credits_invoice_id')->nullable()->after('kind')
                ->constrained('invoices')->restrictOnDelete();
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['credits_invoice_id']);
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'credits_invoice_id']);
        });
    }
};
