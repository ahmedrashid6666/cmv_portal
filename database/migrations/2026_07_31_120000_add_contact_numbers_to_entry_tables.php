<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A contact number (or several) can now be recorded on every entry form:
 * transactions, ledger entries (daily credit / borrowed) and office expenses.
 * Stored as a JSON array of strings so a single entry may hold multiple numbers.
 */
return new class extends Migration
{
    private const TABLES = ['transactions', 'ledger_entries', 'office_expenses'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->json('contact_numbers')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('contact_numbers');
            });
        }
    }
};
