<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportBuilder;

beforeEach(function () {
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->credit = PaymentMethod::create(['name' => 'Credit', 'type' => 'credit']);
    $this->customer = Customer::factory()->create(['name' => 'ESQUBE']);
    $this->builder = app(ReportBuilder::class);
});

function reportTx(array $o): Transaction
{
    $t = Transaction::create(array_merge([
        'transaction_date' => '2026-07-01',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->cash->id,
        'customs_fees' => 245, 'gov_fees' => 0, 'profit' => 35, 'vat_rate' => 0,
    ], $o));

    return $t->recomputeTotals();
}

it('builds a daily report with correct totals', function () {
    reportTx(['transaction_date' => '2026-07-01', 'customs_fees' => 245, 'profit' => 35]);
    reportTx(['transaction_date' => '2026-07-01', 'customs_fees' => 75, 'profit' => 25]);
    reportTx(['transaction_date' => '2026-07-02', 'customs_fees' => 100, 'profit' => 10]);

    $report = $this->builder->build('daily', ['date' => '2026-07-01']);

    expect($report['rows'])->toHaveCount(2)
        ->and($report['totals']['Income'])->toBe(380.0)   // 280 + 100
        ->and($report['totals']['Net Profit'])->toBe(60.0); // 35 + 25
});

it('builds a customer-wise report grouped by customer', function () {
    $other = Customer::factory()->create(['name' => 'BIG BRANDS']);
    reportTx(['customs_fees' => 245, 'profit' => 35]);
    reportTx(['customer_id' => $other->id, 'customs_fees' => 295, 'profit' => 50]);

    $report = $this->builder->build('customer', []);
    expect($report['rows'])->toHaveCount(2)
        ->and($report['totals']['Income'])->toBe(625.0); // 280 + 345
});

it('builds an outstanding-credit report of unpaid balances only', function () {
    reportTx(['payment_method_id' => $this->credit->id, 'customs_fees' => 200, 'profit' => 0, 'credit_amount' => 200]);

    $report = $this->builder->build('outstanding-credit', []);
    expect($report['rows'])->toHaveCount(1)
        ->and($report['totals']['Outstanding'])->toBe(200.0);
});

it('exports a report as xlsx and pdf', function () {
    reportTx([]);
    $admin = User::factory()->role(Role::ADMIN)->create();

    $this->actingAs($admin)->get(route('reports.export', ['daily', 'format' => 'xlsx']))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($admin)->get(route('reports.export', ['daily', 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
