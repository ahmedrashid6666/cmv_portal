<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // machine key stored in transaction.custom_data
            $table->string('label');
            $table->string('type');                    // text | number | date | select
            $table->json('options')->nullable();       // for select
            $table->boolean('required')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->json('custom_data')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn('custom_data'));
        Schema::dropIfExists('custom_fields');
    }
};
