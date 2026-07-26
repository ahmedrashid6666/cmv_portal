<?php

use App\Models\Bank;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\TransactionExpense;
use App\Services\BalanceService;

beforeEach(function () {
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->bank = PaymentMethod::create(['name' => 'Bank', 'type' => 'bank']);
    $this->credit = PaymentMethod::create(['name' => 'Credit', 'type' => 'credit']);
    $this->customer = Customer::factory()->create();
    $this->svc = app(BalanceService::class);
});

function tx(array $o): Transaction
{
    return Transaction::create(array_merge([
        'transaction_date' => '2026-07-01',
        'customer_id' => test()->customer->id,
        'customs_fees' => 0, 'gov_fees' => 0, 'profit' => 0, 'vat_rate' => 0,
    ], $o));
}

it('computes cash balance from cash sales minus expenses', function () {
    // cash sale total 345 (customs 295 + profit 50)
    $t = tx(['payment_method_id' => $this->cash->id, 'customs_fees' => 295, 'profit' => 50]);
    $t->recomputeTotals();
    TransactionExpense::create(['transaction_id' => $t->id, 'amount' => 45, 'description' => 'Fuel']);

    // cash = 0 opening + 345 receipts - 45 expenses = 300
    expect($this->svc->cashBalance())->toBe('300.00');
});

it('computes bank balance from bank opening plus bank sales', function () {
    Bank::create(['name' => 'ADCB', 'opening_balance' => 1000]);
    $t = tx(['payment_method_id' => $this->bank->id, 'customs_fees' => 100, 'profit' => 0]);
    $t->recomputeTotals();

    // bank = 1000 opening + 100 receipts = 1100
    expect($this->svc->bankBalance())->toBe('1100.00');
});

it('tracks credit outstanding and pending count with partial repayment', function () {
    $t = tx(['payment_method_id' => $this->credit->id, 'customs_fees' => 200, 'profit' => 0, 'credit_amount' => 200]);
    $t->recomputeTotals();
    CreditPayment::create(['transaction_id' => $t->id, 'payment_date' => '2026-07-05', 'amount' => 120, 'payment_method_id' => $this->bank->id]);

    expect($this->svc->creditOutstandingTotal())->toBe('80.00')
        ->and($this->svc->pendingCreditsCount())->toBe(1);

    // credit sale does not hit cash; repayment of 120 hits bank
    expect($this->svc->cashBalance())->toBe('0.00')
        ->and($this->svc->bankBalance())->toBe('120.00');
});

it('computes today income, expenses and total profit', function () {
    $t = tx(['payment_method_id' => $this->cash->id, 'customs_fees' => 295, 'profit' => 50, 'transaction_date' => today()->toDateString()]);
    $t->recomputeTotals();
    TransactionExpense::create(['transaction_id' => $t->id, 'amount' => 30, 'description' => 'ZAJEL']);
    $t->recomputeTotals();

    expect($this->svc->todaysIncome())->toBe('345.00')
        ->and($this->svc->todaysExpenses())->toBe('30.00')
        ->and($this->svc->totalProfit())->toBe('20.00'); // 50 profit - 30 expense
});
