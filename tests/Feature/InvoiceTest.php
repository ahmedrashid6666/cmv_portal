<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CreditPayment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\User;

beforeEach(function () {
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->credit = PaymentMethod::create(['name' => 'Credit', 'type' => 'credit']);
    $this->bank = PaymentMethod::create(['name' => 'Bank', 'type' => 'bank']);
    $this->customer = Customer::factory()->create(['name' => 'ESQUBE']);
    $this->user = User::factory()->role(Role::ACCOUNTANT)->create();
});

function invTx(array $o): Transaction
{
    $t = Transaction::create(array_merge([
        'transaction_date' => '2026-07-01',
        'invoice_no' => '56732',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->cash->id,
        'customs_fees' => 295, 'gov_fees' => 0, 'profit' => 50, 'vat_rate' => 0,
    ], $o));

    return $t->recomputeTotals();
}

it('marks a cash sale as paid and a credit sale as unpaid', function () {
    $paid = invTx([]);
    $unpaid = invTx(['invoice_no' => '56733', 'payment_method_id' => $this->credit->id, 'credit_amount' => 345]);

    expect($paid->invoiceStatus())->toBe('paid')
        ->and($unpaid->invoiceStatus())->toBe('unpaid');
});

it('marks a partially-paid credit sale as partial', function () {
    $t = invTx(['invoice_no' => '56734', 'payment_method_id' => $this->credit->id, 'credit_amount' => 345]);
    CreditPayment::create(['transaction_id' => $t->id, 'payment_date' => '2026-07-05', 'amount' => 100, 'payment_method_id' => $this->bank->id]);

    expect($t->invoiceStatus())->toBe('partial');
});

it('shows the invoice list and a single invoice', function () {
    invTx([]);

    $this->actingAs($this->user)->get(route('invoices.index'))->assertOk();
    $this->actingAs($this->user)->get(route('invoices.show', Transaction::first()))->assertOk();
});

it('generates an invoice PDF with correct line total', function () {
    $t = invTx([]);
    TransactionCommission::create(['transaction_id' => $t->id, 'amount' => 25, 'type' => 'charged_to_customer']);
    $t->recomputeTotals();

    $res = $this->actingAs($this->user)->get(route('invoices.pdf', $t));
    $res->assertOk()->assertHeader('content-type', 'application/pdf');
});
