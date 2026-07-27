<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type');                     // daily_credit | borrowed
            $table->date('entry_date');
            $table->string('party_name');               // customer / person name
            $table->string('reference')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);   // paid / returned
            $table->decimal('balance_amount', 12, 2)->default(0);
            $table->string('status')->default('pending');        // pending | partial | returned
            $table->date('return_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'entry_date']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
