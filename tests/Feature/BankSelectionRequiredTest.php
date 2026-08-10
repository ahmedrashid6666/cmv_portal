<?php

use App\Enums\Role;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

/**
 * A bank must be selected whenever the chosen payment method is bank-type —
 * across every form that offers one (Transaction sale receipt, Office
 * Expense, Credit Payment, Bulk Payment/Return). See RequiredBankForPaymentMethod.
 */
beforeEach(function () {
    $this->actor = User::factory()->role(Role::ACCOUNTANT)->create();
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->bankMethod = PaymentMethod::create(['name' => 'Bank Transfer', 'type' => 'bank']);
    $this->bank = Bank::create(['name' => 'RAK', 'account_no' => '1', 'opening_balance' => 0]);
    $this->customer = Customer::factory()->create();
});

it('requires a bank on a transaction paid via a bank-type method, but not a cash-type one', function () {
    $payload = [
        'transaction_date' => '2026-07-01', 'customer_id' => $this->customer->id,
        'customs_fees' => 0, 'gov_fees' => 0, 'other_amount' => 0, 'profit' => 100, 'vat_rate' => 0, 'credit_amount' => 0,
        'payment_method_id' => $this->bankMethod->id,
    ];

    $this->actingAs($this->actor)->post(route('transactions.store'), $payload)
        ->assertSessionHasErrors('bank_id');

    $this->actingAs($this->actor)->post(route('transactions.store'), $payload + ['bank_id' => $this->bank->id])
        ->assertSessionDoesntHaveErrors('bank_id');

    $this->actingAs($this->actor)->post(route('transactions.store'), [...$payload, 'payment_method_id' => $this->cash->id])
        ->assertSessionDoesntHaveErrors('bank_id');
});

it('requires a bank on an office expense paid via a bank-type method', function () {
    $payload = ['expense_date' => '2026-07-01', 'amount' => 50, 'payment_method_id' => $this->bankMethod->id];

    $this->actingAs($this->actor)->post(route('office-expenses.store'), $payload)
        ->assertSessionHasErrors('bank_id');

    $this->actingAs($this->actor)->post(route('office-expenses.store'), $payload + ['bank_id' => $this->bank->id])
        ->assertSessionDoesntHaveErrors('bank_id');
});

it('requires a bank on a credit payment received via a bank-type method', function () {
    $t = Transaction::create([
        'transaction_date' => '2026-07-01', 'customer_id' => $this->customer->id,
        'payment_method_id' => $this->cash->id, 'customs_fees' => 0, 'gov_fees' => 0, 'profit' => 100, 'vat_rate' => 0, 'credit_amount' => 100,
    ]);

    $payload = ['transaction_id' => $t->id, 'payment_date' => '2026-07-05', 'amount' => 50, 'payment_method_id' => $this->bankMethod->id];

    $this->actingAs($this->actor)->post(route('credits.store'), $payload)
        ->assertSessionHasErrors('bank_id');

    $this->actingAs($this->actor)->post(route('credits.store'), $payload + ['bank_id' => $this->bank->id])
        ->assertSessionDoesntHaveErrors('bank_id');
});

it('requires a bank on a Bulk Payment settled via a bank-type method', function () {
    $entry = LedgerEntry::create(['type' => 'daily_credit', 'entry_date' => '2026-07-01', 'party_name' => 'ESQUBE', 'total_amount' => 1000, 'paid_amount' => 0]);

    $payload = [
        'mode' => 'fifo', 'amount' => 200, 'payment_date' => '2026-07-05',
        'payment_method_id' => $this->bankMethod->id, 'entry_ids' => [$entry->id],
    ];

    $this->actingAs($this->actor)->post(route('bulk.store', 'daily-credit'), $payload)
        ->assertSessionHasErrors('bank_id');

    $this->actingAs($this->actor)->post(route('bulk.store', 'daily-credit'), $payload + ['bank_id' => $this->bank->id])
        ->assertSessionDoesntHaveErrors('bank_id');
});

it('flags a transaction, office expense, and credit payment missing their bank in the Operations list', function () {
    Transaction::create([
        'transaction_date' => '2026-07-01', 'customer_id' => $this->customer->id,
        'payment_method_id' => $this->bankMethod->id, 'customs_fees' => 0, 'gov_fees' => 0, 'profit' => 100, 'vat_rate' => 0, 'credit_amount' => 0,
    ]);

    $this->actingAs($this->actor)->get(route('operations.index', ['type' => 'transactions']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('rows.data.0.bank_missing', true));

    \App\Models\OfficeExpense::create([
        'expense_date' => '2026-07-01', 'amount' => 40, 'currency' => 'AED', 'payment_method_id' => $this->bankMethod->id,
    ]);

    $this->actingAs($this->actor)->get(route('operations.index', ['type' => 'office-expenses']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('rows.data.0.bank_missing', true));
});
