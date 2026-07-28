<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->date('count_date')->unique();       // one count per day (upsert)
            $table->json('lines');                        // { AED: {denom: qty}, OMR: {denom: qty} }
            $table->json('extras')->nullable();           // { AED: [{label, amount}], OMR: [...] } bundles/slips
            $table->decimal('total_aed', 14, 2)->default(0);
            $table->decimal('total_omr', 14, 3)->default(0);
            $table->decimal('expected_aed', 14, 2)->default(0);   // system cash balance snapshot
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
    }
};
