<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\TransactionExpense;
use App\Models\Vehicle;
use App\Services\ReportBuilder;

beforeEach(function () {
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->customer = Customer::factory()->create(['name' => 'ESQUBE']);
    $this->builder = app(ReportBuilder::class);
});

function p2Tx(array $o): Transaction
{
    $t = Transaction::create(array_merge([
        'transaction_date' => '2026-07-01',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->cash->id,
        'customs_fees' => 245, 'gov_fees' => 0, 'profit' => 35, 'vat_rate' => 0,
    ], $o));

    return $t->recomputeTotals();
}

it('builds a yearly report grouped by month', function () {
    p2Tx(['transaction_date' => '2026-07-01', 'customs_fees' => 245, 'profit' => 35]);
    p2Tx(['transaction_date' => '2026-03-01', 'customs_fees' => 100, 'profit' => 10]);

    $report = $this->builder->build('yearly', ['year' => 2026]);
    expect($report['rows'])->toHaveCount(12)
        ->and($report['totals']['Income'])->toBe(390.0);
});

it('builds a vehicle-wise report', function () {
    $v = Vehicle::factory()->create(['number' => 'DXB-123']);
    p2Tx(['vehicle_id' => $v->id, 'customs_fees' => 245, 'profit' => 35]);

    $report = $this->builder->build('vehicle', []);
    expect($report['rows'][0][0])->toBe('DXB-123')
        ->and($report['totals']['Income'])->toBe(280.0);
});

it('builds a reference-wise report', function () {
    $r = Reference::factory()->create(['name' => 'JRY']);
    p2Tx(['reference_id' => $r->id]);

    $report = $this->builder->build('reference', []);
    expect(collect($report['rows'])->pluck(0))->toContain('JRY');
});

it('builds a commission report split by type', function () {
    $t = p2Tx([]);
    TransactionCommission::create(['transaction_id' => $t->id, 'amount' => 25, 'type' => 'charged_to_customer']);
    TransactionCommission::create(['transaction_id' => $t->id, 'amount' => 10, 'type' => 'paid_to_reference']);

    $report = $this->builder->build('commission', []);
    expect($report['rows'])->toHaveCount(2)
        ->and($report['totals']['Charged to Customer'])->toBe(25.0)
        ->and($report['totals']['Paid to Reference'])->toBe(10.0);
});

it('builds an expense report grouped by category', function () {
    $t = p2Tx([]);
    TransactionExpense::create(['transaction_id' => $t->id, 'amount' => 27, 'description' => 'ZAJEL']);
    TransactionExpense::create(['transaction_id' => $t->id, 'amount' => 13, 'description' => 'Fuel']);

    $report = $this->builder->build('expense', []);
    expect($report['totals']['Total Expenses'])->toBe(40.0);
});

it('builds income and profit reports per day', function () {
    p2Tx(['customs_fees' => 245, 'profit' => 35]);

    expect($this->builder->build('income', [])['totals']['Income'])->toBe(280.0)
        ->and($this->builder->build('profit', [])['totals']['Profit'])->toBe(35.0);
});

it('builds a custom period list within a date range', function () {
    p2Tx(['transaction_date' => '2026-07-05']);
    p2Tx(['transaction_date' => '2026-08-05']);

    $report = $this->builder->build('custom', ['from' => '2026-07-01', 'to' => '2026-07-31']);
    expect($report['rows'])->toHaveCount(1);
});
