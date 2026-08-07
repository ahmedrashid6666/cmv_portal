<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Other Amount" — a second fee line on a shipment, identical in behaviour
 * to Government Fees: it's part of the VAT-taxable base / total amount, and
 * can optionally be paid from a specific bank (other_bank_id), which drains
 * that bank's balance via BankService (mirrors gov_fees/gov_bank_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('other_amount', 12, 2)->default(0)->after('gov_fees');
        });

        // A real FK forces a full table rebuild on SQLite, which trips over the
        // reserved-word "references" table name. Add the constraint only on
        // drivers that support ALTER TABLE ADD FOREIGN KEY (MySQL in production);
        // on SQLite (tests) a plain nullable column is enough.
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('other_bank_id')->nullable();
            } else {
                $table->foreignId('other_bank_id')->nullable()->constrained('banks')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropColumn('other_bank_id');
            } else {
                $table->dropConstrainedForeignId('other_bank_id');
            }
            $table->dropColumn('other_amount');
        });
    }
};
