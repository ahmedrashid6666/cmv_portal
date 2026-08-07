<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->actor = User::factory()->role(Role::ACCOUNTANT)->create();
    $this->customer = Customer::factory()->create();
    $this->method = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
});

it('defaults a transaction currency to AED', function () {
    $t = Transaction::create([
        'transaction_date' => '2026-07-01', 'customer_id' => $this->customer->id, 'payment_method_id' => $this->method->id,
        'customs_fees' => 100, 'gov_fees' => 0, 'profit' => 10, 'vat_rate' => 0,
    ]);
    expect($t->fresh()->currency)->toBe('AED');
});

it('saves a transaction in OMR via the form', function () {
    $this->actingAs($this->actor)->post(route('transactions.store'), [
        'transaction_date' => '2026-07-01', 'customer_id' => $this->customer->id, 'payment_method_id' => $this->method->id,
        'customs_fees' => 100, 'gov_fees' => 0, 'other_amount' => 0, 'profit' => 10, 'vat_rate' => 0, 'currency' => 'OMR',
    ])->assertRedirect();

    expect(Transaction::first()->currency)->toBe('OMR');
});

it('saves a daily-credit entry in OMR', function () {
    $this->actingAs($this->actor)->post(route('ledger.store', 'daily-credit'), [
        'entry_date' => '2026-07-01', 'party_name' => 'ESQUBE', 'total_amount' => 500, 'paid_amount' => 0, 'currency' => 'OMR',
    ])->assertRedirect();

    expect(LedgerEntry::first()->currency)->toBe('OMR');
});

it('rejects an unsupported currency', function () {
    $this->actingAs($this->actor)->post(route('transactions.store'), [
        'transaction_date' => '2026-07-01', 'customer_id' => $this->customer->id, 'payment_method_id' => $this->method->id,
        'customs_fees' => 100, 'gov_fees' => 0, 'other_amount' => 0, 'profit' => 10, 'vat_rate' => 0, 'currency' => 'USD',
    ])->assertSessionHasErrors('currency');
});
