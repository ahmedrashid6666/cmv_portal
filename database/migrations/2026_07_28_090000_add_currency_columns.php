<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', fn (Blueprint $t) => $t->string('currency', 3)->default('AED')->after('vat_rate'));
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->string('currency', 3)->default('AED')->after('total_amount'));
    }

    public function down(): void
    {
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn('currency'));
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->dropColumn('currency'));
    }
};
