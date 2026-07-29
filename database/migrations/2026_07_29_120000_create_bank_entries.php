<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('item');
            $table->string('description')->nullable();
            $table->string('direction'); // 'in' or 'out'
            $table->decimal('amount', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_entries');
    }
};
