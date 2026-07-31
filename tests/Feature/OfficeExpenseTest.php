<?php

use App\Enums\Role;
use App\Models\OfficeExpense;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\BalanceService;

beforeEach(fn () => $this->actor = User::factory()->role(Role::ACCOUNTANT)->create());

it('records an office expense', function () {
    $cash = PaymentMethod::factory()->create(['type' => 'cash']);

    $this->actingAs($this->actor)->post(route('office-expenses.store'), [
        'expense_date' => '2026-07-28',
        'description' => 'Office rent',
        'amount' => 1500,
        'currency' => 'AED',
        'payment_method_id' => $cash->id,
    ])->assertRedirect(route('operations.index', ['type' => 'office-expenses']));

    $e = OfficeExpense::first();
    expect((float) $e->amount)->toBe(1500.0)
        ->and($e->currency)->toBe('AED')
        ->and($e->created_by)->toBe($this->actor->id);
});

it('reduces the cash balance when paid by a cash method, bank when paid by bank', function () {
    $cash = PaymentMethod::factory()->create(['type' => 'cash']);
    $bank = PaymentMethod::factory()->create(['type' => 'bank']);

    OfficeExpense::factory()->create(['amount' => 200, 'payment_method_id' => $cash->id]);
    OfficeExpense::factory()->create(['amount' => 500, 'payment_method_id' => $bank->id]);

    $balances = app(BalanceService::class);
    expect((float) $balances->cashBalance())->toBe(-200.0)   // no receipts, 200 cash out
        ->and((float) $balances->bankBalance())->toBe(-500.0); // 500 bank out
});

it('edits an office expense and updates it', function () {
    $cash = PaymentMethod::factory()->create(['type' => 'cash']);
    $expense = OfficeExpense::factory()->create(['amount' => 100, 'description' => 'Old', 'payment_method_id' => $cash->id]);

    // the edit page loads with the record
    $this->actingAs($this->actor)->get(route('office-expenses.edit', $expense))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('officeExpense.id', $expense->id)->where('officeExpense.description', 'Old'));

    // update persists
    $this->actingAs($this->actor)->put(route('office-expenses.update', $expense), [
        'expense_date' => '2026-07-30',
        'description' => 'New description',
        'amount' => 275,
        'currency' => 'AED',
        'payment_method_id' => $cash->id,
    ])->assertRedirect(route('operations.index', ['type' => 'office-expenses']));

    $expense->refresh();
    expect((float) $expense->amount)->toBe(275.0)
        ->and($expense->description)->toBe('New description');
});

it('forbids read-only users from editing an expense', function () {
    $expense = OfficeExpense::factory()->create();

    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->get(route('office-expenses.edit', $expense))
        ->assertForbidden();
});

it('validates a required amount and payment method', function () {
    $this->actingAs($this->actor)->post(route('office-expenses.store'), [
        'expense_date' => '2026-07-28',
    ])->assertSessionHasErrors(['amount', 'payment_method_id']);
});

it('lists office expenses in the operations tab', function () {
    OfficeExpense::factory()->count(2)->create();

    $this->actingAs($this->actor)->get(route('operations.index', ['type' => 'office-expenses']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('type', 'office-expenses')->has('rows.data', 2));
});

it('bulk-deletes office expenses via operations', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();
    $rows = OfficeExpense::factory()->count(3)->create();

    $this->actingAs($admin)->post(route('operations.bulk-delete'), [
        'type' => 'office-expenses',
        'ids' => $rows->pluck('id')->all(),
    ])->assertRedirect();

    expect(OfficeExpense::count())->toBe(0)
        ->and(OfficeExpense::withTrashed()->count())->toBe(3);
});

it('forbids read-only users from recording an expense', function () {
    $cash = PaymentMethod::factory()->create(['type' => 'cash']);

    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('office-expenses.store'), [
            'expense_date' => '2026-07-28', 'amount' => 10, 'payment_method_id' => $cash->id,
        ])->assertForbidden();
});
