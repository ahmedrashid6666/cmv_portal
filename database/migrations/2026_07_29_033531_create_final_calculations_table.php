<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_calculations', function (Blueprint $table) {
            $table->id();
            $table->date('calc_date')->unique();          // one snapshot per day (upsert)
            $table->json('data');                          // full row figures as saved (see FinalCalculationService)
            // Computed totals — stored so the history list needs no recompute.
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_ac_balance', 15, 2)->default(0);
            $table->decimal('total_debt_exp', 15, 2)->default(0);
            $table->decimal('liquid_cash', 15, 2)->default(0);
            $table->decimal('cash_counted', 15, 2)->default(0);
            $table->decimal('cash_extra', 15, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_calculations');
    }
};
