<?php

use App\Models\Bank;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\OfficeExpense;
use App\Models\PaymentMethod;
use App\Models\Setting;
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

it('recognizes the received (non-credit) portion of a partial credit sale as cash', function () {
    // grand_total 330 (customs 245 + profit 35 + Com-1 50), of which 110 is on credit.
    $t = tx(['payment_method_id' => $this->credit->id, 'customs_fees' => 245, 'profit' => 35, 'credit_amount' => 110]);
    $t->commissions()->create(['label' => 'Com-1', 'amount' => 50, 'type' => 'charged_to_customer']);
    $t->recomputeTotals();

    // 330 − 110 = 220 received in cash; 110 remains a receivable.
    expect($this->svc->cashBalance())->toBe('220.00')
        ->and($this->svc->creditOutstandingTotal())->toBe('110.00');
});

it('excludes the credit portion of a cash sale from the cash balance', function () {
    // cash sale grand_total 300, of which 100 is on credit → only 200 received.
    $t = tx(['payment_method_id' => $this->cash->id, 'customs_fees' => 300, 'profit' => 0, 'credit_amount' => 100]);
    $t->recomputeTotals();

    expect($this->svc->cashBalance())->toBe('200.00')
        ->and($this->svc->creditOutstandingTotal())->toBe('100.00');
});

it('computes the Daily Work Sheet Bal as (opening + profit) - (credit outstanding + office expenses)', function () {
    Setting::put('cash_opening_balance', 1000);

    $t = tx(['payment_method_id' => $this->credit->id, 'customs_fees' => 500, 'profit' => 80, 'credit_amount' => 200]);
    $t->recomputeTotals();

    $category = ExpenseCategory::create(['name' => 'Rent']);
    OfficeExpense::create([
        'expense_date' => '2026-07-01', 'expense_category_id' => $category->id,
        'amount' => 60, 'currency' => 'AED', 'payment_method_id' => $this->cash->id,
    ]);

    // (1000 opening + 80 profit) - (200 credit outstanding + 60 office expenses) = 820
    expect($this->svc->dwsBalance('2026-07-01'))->toBe('820.00');
});

it('nets a credit repayment out of dwsBalance only once it is dated on/before the worksheet date', function () {
    Setting::put('cash_opening_balance', 0);

    $t = tx(['payment_method_id' => $this->credit->id, 'customs_fees' => 200, 'profit' => 0, 'credit_amount' => 200]);
    $t->recomputeTotals();
    CreditPayment::create(['transaction_id' => $t->id, 'payment_date' => '2026-07-10', 'amount' => 150, 'payment_method_id' => $this->bank->id]);

    // Before the repayment: full 200 still outstanding.
    expect($this->svc->dwsBalance('2026-07-05'))->toBe('-200.00');
    // On/after the repayment date: only the remaining 50 is outstanding.
    expect($this->svc->dwsBalance('2026-07-10'))->toBe('-50.00');
});

it('excludes transactions and expenses dated after the worksheet date from dwsBalance', function () {
    Setting::put('cash_opening_balance', 0);

    tx(['payment_method_id' => $this->cash->id, 'customs_fees' => 0, 'profit' => 100, 'transaction_date' => '2026-07-01'])->recomputeTotals();
    tx(['payment_method_id' => $this->cash->id, 'customs_fees' => 0, 'profit' => 999, 'transaction_date' => '2026-07-15'])->recomputeTotals();

    expect($this->svc->dwsBalance('2026-07-01'))->toBe('100.00');
});
