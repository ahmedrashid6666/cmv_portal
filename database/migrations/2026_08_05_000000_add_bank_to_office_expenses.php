<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An office expense paid via a bank-type payment method can optionally name
 * WHICH bank it was paid from, so BankService can drain that bank's balance
 * (mirrors how Transaction.gov_bank_id drains a specific bank for gov fees).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A real FK forces a full table rebuild on SQLite, which trips over the
        // reserved-word "references" table name. Add the constraint only on
        // drivers that support ALTER TABLE ADD FOREIGN KEY (MySQL in production);
        // on SQLite (tests) a plain nullable column is enough.
        Schema::table('office_expenses', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('bank_id')->nullable();
            } else {
                $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('office_expenses', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropColumn('bank_id');
            } else {
                $table->dropConstrainedForeignId('bank_id');
            }
        });
    }
};
