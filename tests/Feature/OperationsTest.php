<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->role(Role::SUPER_ADMIN)->create();
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->customer = Customer::factory()->create();
});

function opTx(string $date): Transaction
{
    return Transaction::create([
        'transaction_date' => $date, 'invoice_no' => (string) fake()->unique()->numberBetween(1, 99999),
        'customer_id' => test()->customer->id, 'payment_method_id' => test()->cash->id,
        'customs_fees' => 100, 'gov_fees' => 0, 'profit' => 25, 'vat_rate' => 0,
    ]);
}

it('defaults the operations list to today', function () {
    opTx(today()->toDateString());
    opTx(today()->subDays(5)->toDateString());

    $this->actingAs($this->admin)->get(route('operations.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('type', 'transactions')
            ->where('filters.from', today()->toDateString())
            ->where('rows.data', fn ($rows) => count($rows) === 1)); // only today's
});

it('lists a wider range when dates are provided', function () {
    opTx(today()->toDateString());
    opTx(today()->subDays(5)->toDateString());

    $this->actingAs($this->admin)->get(route('operations.index', ['from' => today()->subDays(10)->toDateString(), 'to' => today()->toDateString()]))
        ->assertInertia(fn ($p) => $p->where('rows.data', fn ($rows) => count($rows) === 2));
});

it('switches to the daily-credit type', function () {
    LedgerEntry::factory()->create(['entry_date' => today()->toDateString()]);

    $this->actingAs($this->admin)->get(route('operations.index', ['type' => 'daily-credit']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('type', 'daily-credit')->where('isLedger', true));
});

it('bulk-deletes selected transactions to the recycle bin', function () {
    $a = opTx(today()->toDateString());
    $b = opTx(today()->toDateString());

    $this->actingAs($this->admin)->post(route('operations.bulk-delete'), ['type' => 'transactions', 'ids' => [$a->id, $b->id]])
        ->assertRedirect();

    expect(Transaction::count())->toBe(0)
        ->and(Transaction::onlyTrashed()->count())->toBe(2);
});

it('forbids bulk delete for accountants', function () {
    $a = opTx(today()->toDateString());
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->post(route('operations.bulk-delete'), ['type' => 'transactions', 'ids' => [$a->id]])
        ->assertForbidden();
});
