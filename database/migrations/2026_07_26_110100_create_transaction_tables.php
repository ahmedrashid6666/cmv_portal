<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->string('invoice_no')->nullable();
            $table->string('boe_no')->nullable();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('reference_id')->nullable()->constrained('references')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('customs_fees', 12, 2)->default(0);
            $table->decimal('gov_fees', 12, 2)->default(0);
            $table->decimal('profit', 12, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->string('attachment_path')->nullable();

            // cached derived read-model, recomputed on every write
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('net_profit', 12, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('transaction_date');
            $table->index('invoice_no');
            $table->index('boe_no');
        });

        Schema::create('transaction_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('transaction_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->enum('type', ['charged_to_customer', 'paid_to_reference'])->default('charged_to_customer');
            $table->foreignId('reference_id')->nullable()->constrained('references')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('credit_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_payments');
        Schema::dropIfExists('transaction_commissions');
        Schema::dropIfExists('transaction_expenses');
        Schema::dropIfExists('transactions');
    }
};
