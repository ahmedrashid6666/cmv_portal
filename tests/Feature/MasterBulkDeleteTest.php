<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Transaction;
use App\Models\User;

beforeEach(fn () => $this->admin = User::factory()->role(Role::SUPER_ADMIN)->create());

it('bulk-deletes selected masters', function () {
    $a = Reference::create(['name' => 'A']);
    $b = Reference::create(['name' => 'B']);
    Reference::create(['name' => 'C']);

    $this->actingAs($this->admin)
        ->post(route('masters.bulk-destroy', 'references'), ['ids' => [$a->id, $b->id]])
        ->assertRedirect();

    expect(Reference::count())->toBe(1); // only C remains (soft-deletes excluded)
});

it('skips masters that are still in use (FK protected)', function () {
    $cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $free = PaymentMethod::create(['name' => 'Unused', 'type' => 'other']);
    $customer = Customer::factory()->create();
    Transaction::create([
        'transaction_date' => '2026-07-01', 'customer_id' => $customer->id, 'payment_method_id' => $cash->id,
        'customs_fees' => 100, 'gov_fees' => 0, 'profit' => 10, 'vat_rate' => 0,
    ]);

    $this->actingAs($this->admin)
        ->post(route('masters.bulk-destroy', 'payment-methods'), ['ids' => [$cash->id, $free->id]])
        ->assertRedirect();

    // the in-use Cash is skipped; the unused one is deleted
    expect(PaymentMethod::whereKey($cash->id)->exists())->toBeTrue()
        ->and(PaymentMethod::whereKey($free->id)->exists())->toBeFalse();
});

it('forbids bulk delete for accountants', function () {
    $r = Reference::create(['name' => 'X']);
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->post(route('masters.bulk-destroy', 'references'), ['ids' => [$r->id]])
        ->assertForbidden();
});
