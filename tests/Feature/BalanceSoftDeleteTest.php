<?php

use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionExpense;
use App\Services\BalanceService;

beforeEach(function () {
    Setting::put('cash_opening_balance', 0);
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->bank = PaymentMethod::create(['name' => 'Bank', 'type' => 'bank']);
    $this->credit = PaymentMethod::create(['name' => 'Credit', 'type' => 'credit']);
    $this->customer = Customer::factory()->create();
    $this->svc = app(BalanceService::class);
});

function balTx(array $o): Transaction
{
    $t = Transaction::create(array_merge([
        'transaction_date' => '2026-07-01',
        'customer_id' => test()->customer->id,
        'customs_fees' => 0, 'gov_fees' => 0, 'profit' => 0, 'vat_rate' => 0,
    ], $o));

    return $t->recomputeTotals();
}

it('excludes a soft-deleted transaction and its expenses from balances', function () {
    $t = balTx(['payment_method_id' => $this->cash->id, 'customs_fees' => 295, 'profit' => 50]); // +345 cash
    TransactionExpense::create(['transaction_id' => $t->id, 'amount' => 27, 'description' => 'ZAJEL']); // -27 cash

    expect($this->svc->cashBalance())->toBe('318.00'); // 345 - 27

    $t->delete(); // soft delete

    // everything for the deleted transaction must drop out
    expect($this->svc->cashBalance())->toBe('0.00')
        ->and($this->svc->todaysIncome())->toBe('0.00')
        ->and($this->svc->todaysExpenses())->toBe('0.00')
        ->and($this->svc->totalProfit())->toBe('0.00');
});

it('excludes credit repayments of a soft-deleted transaction from bank balance', function () {
    $t = balTx(['payment_method_id' => $this->credit->id, 'customs_fees' => 200, 'credit_amount' => 200]);
    CreditPayment::create(['transaction_id' => $t->id, 'payment_date' => '2026-07-05', 'amount' => 120, 'payment_method_id' => $this->bank->id]);

    expect($this->svc->bankBalance())->toBe('120.00');

    $t->delete();

    expect($this->svc->bankBalance())->toBe('0.00')
        ->and($this->svc->creditOutstandingTotal())->toBe('0.00');
});
