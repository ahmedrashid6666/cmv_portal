<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Government fees are disbursed from a bank the user picks per shipment.
 * Customs fees are always paid from the CDR bank (resolved by name/setting),
 * so they need no per-row column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A real FK forces a full table rebuild on SQLite, which trips over the
        // reserved-word "references" table name. Add the constraint only on
        // drivers that support ALTER TABLE ADD FOREIGN KEY (MySQL in production);
        // on SQLite (tests) a plain nullable column is enough.
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('gov_bank_id')->nullable();
            } else {
                $table->foreignId('gov_bank_id')->nullable()->constrained('banks')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropColumn('gov_bank_id');
            } else {
                $table->dropConstrainedForeignId('gov_bank_id');
            }
        });
    }
};
