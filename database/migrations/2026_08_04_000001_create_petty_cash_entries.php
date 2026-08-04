<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->string('item');
            $table->text('description')->nullable();
            $table->decimal('in_amount', 12, 2)->default(0);
            $table->decimal('out_amount', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('entry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_entries');
    }
};
