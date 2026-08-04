<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entry_details', function (Blueprint $table) {
            $table->decimal('returned_amount', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entry_details', function (Blueprint $table) {
            $table->dropColumn('returned_amount');
        });
    }
};
