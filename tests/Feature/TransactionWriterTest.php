<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\TransactionWriter;

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $this->method = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->writer = app(TransactionWriter::class);
});

function payload(array $o = []): array
{
    return array_merge([
        'transaction_date' => '2026-07-01',
        'invoice_no' => '56732',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->method->id,
        'customs_fees' => 295, 'gov_fees' => 0, 'profit' => 50, 'vat_rate' => 0,
        'credit_amount' => 0,
        'expenses' => [], 'commissions' => [],
    ], $o);
}

it('creates a transaction with nested expenses and commissions and computes totals', function () {
    $t = $this->writer->create(payload([
        'expenses' => [['description' => 'ZAJEL', 'amount' => 27]],
        'commissions' => [
            ['label' => 'Com-1', 'amount' => 25, 'type' => 'charged_to_customer'],
            ['label' => 'Com-2', 'amount' => 10, 'type' => 'paid_to_reference'],
        ],
    ]));

    expect($t->expenses)->toHaveCount(1)
        ->and($t->commissions)->toHaveCount(2)
        ->and((float) $t->total_amount)->toBe(345.0)
        ->and((float) $t->grand_total)->toBe(370.0)   // 345 + 25 to customer
        ->and((float) $t->net_profit)->toBe(13.0);    // 50 - 27 - 10
});

it('replaces children on update', function () {
    $t = $this->writer->create(payload([
        'expenses' => [['description' => 'A', 'amount' => 10]],
    ]));

    $this->writer->update($t, payload([
        'expenses' => [['description' => 'B', 'amount' => 5], ['description' => 'C', 'amount' => 5]],
    ]));

    $t->refresh();
    expect($t->expenses)->toHaveCount(2)
        ->and($t->expenses->pluck('description')->all())->toBe(['B', 'C']);
});

it('skips empty expense and commission rows', function () {
    $t = $this->writer->create(payload([
        'expenses' => [['description' => null, 'amount' => null]],
        'commissions' => [['label' => null, 'amount' => null]],
    ]));

    expect($t->expenses)->toHaveCount(0)
        ->and($t->commissions)->toHaveCount(0);
});
