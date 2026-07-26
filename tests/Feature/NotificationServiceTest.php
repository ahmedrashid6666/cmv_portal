<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionExpense;
use App\Services\NotificationService;

beforeEach(function () {
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->credit = PaymentMethod::create(['name' => 'Credit', 'type' => 'credit']);
    $this->customer = Customer::factory()->create();
    $this->svc = app(NotificationService::class);
});

function notifTx(array $o): Transaction
{
    $t = Transaction::create(array_merge([
        'transaction_date' => today()->toDateString(),
        'customer_id' => test()->customer->id,
        'customs_fees' => 0, 'gov_fees' => 0, 'profit' => 0, 'vat_rate' => 0,
    ], $o));

    return $t->recomputeTotals();
}

it('always includes a monthly profit summary', function () {
    $titles = collect($this->svc->alerts())->pluck('title');
    expect($titles)->toContain('Monthly profit');
});

it('raises a low cash alert when below threshold (with activity)', function () {
    Setting::put('low_cash_threshold', 500);
    // a small cash sale leaves cash at 100, below 500
    notifTx(['payment_method_id' => $this->cash->id, 'customs_fees' => 100, 'profit' => 0]);

    expect(collect($this->svc->alerts())->pluck('title'))->toContain('Low cash balance');
});

it('does not nag about low cash on an empty system', function () {
    Setting::put('low_cash_threshold', 500);
    // no transactions, no opening balance

    expect(collect($this->svc->alerts())->pluck('title'))->not->toContain('Low cash balance');
});

it('raises a pending-credits alert', function () {
    notifTx(['payment_method_id' => $this->credit->id, 'customs_fees' => 200, 'credit_amount' => 200]);

    expect(collect($this->svc->alerts())->pluck('title'))->toContain('Pending credits');
});

it('raises a large-expense alert above threshold', function () {
    Setting::put('large_expense_threshold', 1000);
    Setting::put('low_cash_threshold', 0); // avoid low-cash noise
    $t = notifTx(['payment_method_id' => $this->cash->id, 'customs_fees' => 5000, 'profit' => 0]);
    TransactionExpense::create(['transaction_id' => $t->id, 'amount' => 1500, 'description' => 'Big']);

    expect(collect($this->svc->alerts())->pluck('title'))->toContain('Large expenses');
});
