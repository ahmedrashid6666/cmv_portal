<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's own bank accounts, shown on customer-facing PDFs (Outstanding
 * Statement) so a customer knows where to send payment. Deliberately separate
 * from `banks` (Bank Accounts / BankService), which tracks CMV's internal
 * cash-flow ledger — this table is display-only payment info, and a bank can
 * exist here without being one of the internal ledgered accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_bank_details', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number');
            $table->string('iban')->nullable();
            $table->string('swift_code')->nullable();
            $table->string('branch')->nullable();
            $table->string('currency')->default('AED');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_details');
    }
};
