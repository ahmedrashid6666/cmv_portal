<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomField;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $this->method = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->actor = User::factory()->role(Role::ACCOUNTANT)->create();
});

function withCustom(array $custom): array
{
    return [
        'transaction_date' => '2026-07-01',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->method->id,
        'customs_fees' => 100, 'gov_fees' => 0, 'other_amount' => 0, 'profit' => 20, 'vat_rate' => 0,
        'custom_data' => $custom,
    ];
}

it('lets a super admin define a custom field', function () {
    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->post(route('custom-fields.store'), ['label' => 'Container No', 'type' => 'text', 'required' => true])
        ->assertRedirect();

    expect(CustomField::where('label', 'Container No')->first()->key)->toBe('container_no');
});

it('saves custom field values on a transaction', function () {
    CustomField::create(['key' => 'container_no', 'label' => 'Container No', 'type' => 'text', 'required' => false, 'active' => true]);

    $this->actingAs($this->actor)
        ->post(route('transactions.store'), withCustom(['container_no' => 'MSKU1234567']))
        ->assertRedirect(route('transactions.index'));

    expect(Transaction::first()->custom_data)->toBe(['container_no' => 'MSKU1234567']);
});

it('enforces a required custom field', function () {
    CustomField::create(['key' => 'container_no', 'label' => 'Container No', 'type' => 'text', 'required' => true, 'active' => true]);

    $this->actingAs($this->actor)
        ->post(route('transactions.store'), withCustom([]))
        ->assertSessionHasErrors('custom_data.container_no');
});

it('strips values for undefined custom fields', function () {
    CustomField::create(['key' => 'container_no', 'label' => 'Container No', 'type' => 'text', 'active' => true]);

    $this->actingAs($this->actor)
        ->post(route('transactions.store'), withCustom(['container_no' => 'X1', 'hacker_field' => 'nope']))
        ->assertRedirect();

    expect(Transaction::first()->custom_data)->toBe(['container_no' => 'X1']);
});

it('restricts custom field management to super admin', function () {
    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('custom-fields.index'))->assertForbidden();
});
