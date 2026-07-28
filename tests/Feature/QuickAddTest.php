<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\Vehicle;

it('quick-adds a customer from a dropdown and returns it as json', function () {
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->postJson(route('masters.quick', 'customers'), ['name' => 'NEW CUSTOMER LLC'])
        ->assertOk()
        ->assertJsonStructure(['id', 'label'])
        ->assertJsonPath('label', 'NEW CUSTOMER LLC');

    expect(Customer::where('name', 'NEW CUSTOMER LLC')->exists())->toBeTrue();
});

it('quick-adds a vehicle by number and is idempotent', function () {
    $user = User::factory()->role(Role::ADMIN)->create();

    $this->actingAs($user)->postJson(route('masters.quick', 'vehicles'), ['number' => 'DXB-999'])->assertOk();
    $this->actingAs($user)->postJson(route('masters.quick', 'vehicles'), ['number' => 'DXB-999'])->assertOk();

    expect(Vehicle::where('number', 'DXB-999')->count())->toBe(1);
});

it('quick-adds an expense category from the office-expense dropdown', function () {
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->postJson(route('masters.quick', 'expense-categories'), ['name' => 'Cleaning'])
        ->assertOk()
        ->assertJsonPath('label', 'Cleaning');

    expect(ExpenseCategory::where('name', 'Cleaning')->exists())->toBeTrue();
});

it('rejects quick-add for non-allowed master types', function () {
    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->postJson(route('masters.quick', 'payment-methods'), ['name' => 'X'])
        ->assertNotFound();
});

it('forbids read-only users from quick-adding', function () {
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->postJson(route('masters.quick', 'customers'), ['name' => 'X'])
        ->assertForbidden();
});
