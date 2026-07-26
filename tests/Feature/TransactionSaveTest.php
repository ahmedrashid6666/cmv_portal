<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\TransactionExpense;

function makeBaseTransaction(array $overrides = []): Transaction
{
    return Transaction::create(array_merge([
        'transaction_date' => '2026-07-01',
        'customer_id' => Customer::factory()->create()->id,
        'payment_method_id' => PaymentMethod::factory()->create()->id,
        'customs_fees' => 295,
        'gov_fees' => 0,
        'profit' => 50,
        'vat_rate' => 0,
    ], $overrides));
}

it('derives vat_amount and total_amount on save', function () {
    $t = makeBaseTransaction();

    expect((float) $t->vat_amount)->toBe(0.0)
        ->and((float) $t->total_amount)->toBe(345.0);
});

it('applies a non-zero vat rate on save', function () {
    $t = makeBaseTransaction(['customs_fees' => 100, 'profit' => 0, 'vat_rate' => 5]);

    // taxable = 100, vat = 5, total = 105
    expect((float) $t->vat_amount)->toBe(5.0)
        ->and((float) $t->total_amount)->toBe(105.0);
});

it('recomputes grand_total and net_profit from children', function () {
    $t = makeBaseTransaction();

    TransactionCommission::create(['transaction_id' => $t->id, 'amount' => 25, 'type' => 'charged_to_customer']);
    TransactionCommission::create(['transaction_id' => $t->id, 'amount' => 10, 'type' => 'paid_to_reference']);
    TransactionExpense::create(['transaction_id' => $t->id, 'amount' => 30, 'description' => 'ZAJEL']);

    $t->recomputeTotals();

    // grand total = 345 + 25 (to customer) = 370
    // net profit  = 50 - 30 (expenses) - 10 (payable) = 10
    expect((float) $t->grand_total)->toBe(370.0)
        ->and((float) $t->net_profit)->toBe(10.0);
});
