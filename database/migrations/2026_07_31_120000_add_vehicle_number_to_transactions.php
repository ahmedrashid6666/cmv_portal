<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('vehicle_number')->nullable()->after('reference_id');
        });

        // Preserve existing data: copy each transaction's linked vehicle number
        // into the new free-text column. The vehicle_id column is kept (unused)
        // so nothing is lost and the change stays reversible.
        DB::table('transactions')
            ->whereNotNull('vehicle_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $number = DB::table('vehicles')->where('id', $row->vehicle_id)->value('number');
                    if ($number !== null) {
                        DB::table('transactions')->where('id', $row->id)->update(['vehicle_number' => $number]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('vehicle_number');
        });
    }
};
