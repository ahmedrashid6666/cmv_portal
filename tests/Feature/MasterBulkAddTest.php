<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\User;

beforeEach(fn () => $this->admin = User::factory()->role(Role::SUPER_ADMIN)->create());

it('bulk-adds customers from a comma-separated list', function () {
    $this->actingAs($this->admin)
        ->post(route('masters.bulk', 'customers'), ['values' => 'ALPHA LLC, BETA TRADING, GAMMA FZE'])
        ->assertRedirect();

    expect(Customer::whereIn('name', ['ALPHA LLC', 'BETA TRADING', 'GAMMA FZE'])->count())->toBe(3);
});

it('trims, de-duplicates and skips existing values', function () {
    ExpenseCategory::create(['name' => 'Fuel']);

    $this->actingAs($this->admin)
        ->post(route('masters.bulk', 'expense-categories'), ['values' => ' Fuel , Salik ,Salik,  Parking '])
        ->assertRedirect();

    expect(ExpenseCategory::whereIn('name', ['Salik', 'Parking'])->count())->toBe(2)
        ->and(ExpenseCategory::where('name', 'Fuel')->count())->toBe(1)   // not duplicated
        ->and(ExpenseCategory::where('name', 'Salik')->count())->toBe(1); // de-duped within the list
});

it('applies defaults for secondary required fields (payment method type)', function () {
    $this->actingAs($this->admin)
        ->post(route('masters.bulk', 'payment-methods'), ['values' => 'Wallet, Crypto'])
        ->assertRedirect();

    expect(PaymentMethod::where('name', 'Wallet')->first()->type)->toBe('cash'); // first option default
});

it('forbids bulk add for accountants', function () {
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->post(route('masters.bulk', 'customers'), ['values' => 'X'])
        ->assertForbidden();
});
