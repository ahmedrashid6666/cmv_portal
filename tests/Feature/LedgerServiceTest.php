<?php

use App\Models\Bank;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionExpense;
use App\Services\LedgerService;

beforeEach(function () {
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->bank = PaymentMethod::create(['name' => 'Bank', 'type' => 'bank']);
    $this->credit = PaymentMethod::create(['name' => 'Credit', 'type' => 'credit']);
    $this->customer = Customer::factory()->create(['name' => 'ESQUBE']);
    $this->svc = app(LedgerService::class);
});

function ledgerTx(array $o): Transaction
{
    $t = Transaction::create(array_merge([
        'transaction_date' => '2026-07-01',
        'customer_id' => test()->customer->id,
        'customs_fees' => 0, 'gov_fees' => 0, 'profit' => 0, 'vat_rate' => 0,
    ], $o));

    return $t->recomputeTotals();
}

it('builds a cash book with running balance', function () {
    Setting::put('cash_opening_balance', 500);
    $t = ledgerTx(['payment_method_id' => $this->cash->id, 'customs_fees' => 295, 'profit' => 50, 'transaction_date' => '2026-07-01']); // +345
    TransactionExpense::create(['transaction_id' => $t->id, 'amount' => 45, 'description' => 'Fuel']); // -45

    $book = $this->svc->cashBook();

    expect($book['opening'])->toBe(500.0)
        ->and($book['rows'])->toHaveCount(2)
        ->and($book['closing'])->toBe(800.0)   // 500 + 345 - 45
        ->and($book['totals']['debit'])->toBe(345.0)
        ->and($book['totals']['credit'])->toBe(45.0);
});

it('lists the received portion of a partial credit sale in the cash book', function () {
    // Credit-method sale: grand_total 330 (customs 245 + profit 35 + Com-1 50),
    // of which 110 is on credit → 220 was received in cash and must show.
    $t = ledgerTx(['payment_method_id' => $this->credit->id, 'customs_fees' => 245, 'profit' => 35, 'credit_amount' => 110]);
    $t->commissions()->create(['label' => 'Com-1', 'amount' => 50, 'type' => 'charged_to_customer']);
    $t->recomputeTotals();

    $book = $this->svc->cashBook();

    expect($book['rows'])->toHaveCount(1)
        ->and($book['rows'][0]['debit'])->toBe(220.0)   // 330 − 110
        ->and($book['closing'])->toBe(220.0);
});

it('excludes a fully-credit sale from the cash book', function () {
    ledgerTx(['payment_method_id' => $this->credit->id, 'customs_fees' => 200, 'credit_amount' => 200]); // received 0

    expect($this->svc->cashBook()['rows'])->toHaveCount(0);
});

it('builds a bank book from bank opening and bank sales', function () {
    Bank::create(['name' => 'ADCB', 'opening_balance' => 1000]);
    ledgerTx(['payment_method_id' => $this->bank->id, 'customs_fees' => 100, 'transaction_date' => '2026-07-02']); // +100

    $book = $this->svc->bankBook();
    expect($book['opening'])->toBe(1000.0)
        ->and($book['closing'])->toBe(1100.0);
});

it('builds a customer ledger with outstanding running balance', function () {
    $t = ledgerTx(['payment_method_id' => $this->credit->id, 'customs_fees' => 200, 'credit_amount' => 200, 'transaction_date' => '2026-07-01']);
    CreditPayment::create(['transaction_id' => $t->id, 'payment_date' => '2026-07-05', 'amount' => 120, 'payment_method_id' => $this->bank->id]);

    $ledger = $this->svc->customerLedger($this->customer->id);

    expect($ledger['rows'])->toHaveCount(2)
        ->and($ledger['closing'])->toBe(80.0)   // 200 owed - 120 paid
        ->and($ledger['customer'])->toBe('ESQUBE');
});

it('carries forward opening balance when filtering by date range', function () {
    Setting::put('cash_opening_balance', 0);
    ledgerTx(['payment_method_id' => $this->cash->id, 'customs_fees' => 100, 'transaction_date' => '2026-06-30']); // before window
    ledgerTx(['payment_method_id' => $this->cash->id, 'customs_fees' => 50, 'transaction_date' => '2026-07-02']);  // in window

    $book = $this->svc->cashBook(['from' => '2026-07-01', 'to' => '2026-07-31']);

    expect($book['opening'])->toBe(100.0)   // June sale rolled into opening
        ->and($book['rows'])->toHaveCount(1)
        ->and($book['closing'])->toBe(150.0);
});
