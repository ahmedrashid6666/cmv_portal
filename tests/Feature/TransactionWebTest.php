<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $this->method = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->category = ExpenseCategory::create(['name' => 'Fuel']);
});

function fullPayload(): array
{
    return [
        'transaction_date' => '2026-07-01',
        'invoice_no' => '56732',
        'boe_no' => '2010029464726',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->method->id,
        'customs_fees' => 295, 'gov_fees' => 0, 'profit' => 50, 'vat_rate' => 0,
        'credit_amount' => 0,
        'expenses' => [['expense_category_id' => test()->category->id, 'description' => 'Fuel', 'amount' => 27]],
        'commissions' => [['label' => 'Com-1', 'amount' => 25, 'type' => 'charged_to_customer']],
    ];
}

it('lets an accountant create a transaction with correct computed totals', function () {
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->post(route('transactions.store'), fullPayload())
        ->assertRedirect(route('transactions.index'));

    $t = Transaction::first();
    expect((float) $t->total_amount)->toBe(345.0)
        ->and((float) $t->grand_total)->toBe(370.0)
        ->and((float) $t->net_profit)->toBe(23.0)   // 50 - 27
        ->and($t->expenses)->toHaveCount(1)
        ->and($t->commissions)->toHaveCount(1);
});

it('forbids a read-only user from creating transactions', function () {
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('transactions.store'), fullPayload())
        ->assertForbidden();

    expect(Transaction::count())->toBe(0);
});

it('validates required fields', function () {
    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->post(route('transactions.store'), ['customs_fees' => -5])
        ->assertSessionHasErrors(['transaction_date', 'customer_id', 'payment_method_id', 'customs_fees']);
});

it('shows the transactions list to a read-only user', function () {
    Transaction::factory()->create();

    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->get(route('transactions.index'))
        ->assertOk();
});
