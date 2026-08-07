<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bank is the customs (CDR) disbursement account was previously
 * detected only by an exact, case-insensitive name match against "CDR" —
 * silently resolving to null (and miscounting every downstream balance) for
 * any bank not named exactly that. An explicit flag is the reliable source
 * of truth; the name match is kept as a fallback for old, unflagged data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->boolean('is_customs')->default(false)->after('account_no');
        });
    }

    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropColumn('is_customs');
        });
    }
};
