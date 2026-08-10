<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale's own receipt (grand_total − credit_amount) can now name WHICH bank
 * it landed in when paid via a bank-type payment method, so BankService can
 * attribute it to a specific account instead of leaving it "unassigned"
 * (mirrors gov_bank_id / other_bank_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('bank_id')->nullable();
            } else {
                $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropColumn('bank_id');
            } else {
                $table->dropConstrainedForeignId('bank_id');
            }
        });
    }
};
