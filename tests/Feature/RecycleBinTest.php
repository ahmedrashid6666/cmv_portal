<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->role(Role::SUPER_ADMIN)->create();
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->customer = Customer::factory()->create();
});

function binTx(): Transaction
{
    return Transaction::create([
        'transaction_date' => '2026-07-01',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->cash->id,
        'customs_fees' => 100, 'gov_fees' => 0, 'profit' => 25, 'vat_rate' => 0,
    ]);
}

it('restores a soft-deleted transaction', function () {
    $t = binTx();
    $t->delete();

    $this->actingAs($this->admin)->put(route('bin.restore', $t->id))->assertRedirect();

    expect(Transaction::whereKey($t->id)->exists())->toBeTrue()
        ->and(Transaction::onlyTrashed()->whereKey($t->id)->exists())->toBeFalse();
});

it('permanently deletes a transaction from the bin', function () {
    $t = binTx();
    $t->delete();

    $this->actingAs($this->admin)->delete(route('bin.force-delete', $t->id))->assertRedirect();

    expect(Transaction::withTrashed()->whereKey($t->id)->exists())->toBeFalse();
});

it('is restricted to super admin', function () {
    $t = binTx();
    $t->delete();

    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('bin.index'))->assertForbidden();
});
