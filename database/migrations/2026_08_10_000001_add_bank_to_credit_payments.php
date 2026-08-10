<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credit repayment paid via a bank-type payment method can now name WHICH
 * bank it landed in, so BankService can attribute it to a specific account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_payments', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('bank_id')->nullable();
            } else {
                $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_payments', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropColumn('bank_id');
            } else {
                $table->dropConstrainedForeignId('bank_id');
            }
        });
    }
};
