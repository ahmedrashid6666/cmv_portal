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

it('defaults the operations list to all dates', function () {
    opTx(today()->toDateString());
    opTx(today()->subDays(5)->toDateString());

    $this->actingAs($this->admin)->get(route('operations.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('type', 'transactions')
            ->where('filters.from', null)
            ->where('rows.data', fn ($rows) => count($rows) === 2)); // never empty
});

it('folds invoices and credits into tabs', function () {
    opTx(today()->toDateString());

    $this->actingAs($this->admin)->get(route('operations.index', ['type' => 'invoices']))
        ->assertInertia(fn ($p) => $p->where('type', 'invoices')->where('actionLabel', 'View'));
    $this->actingAs($this->admin)->get(route('operations.index', ['type' => 'credits']))
        ->assertInertia(fn ($p) => $p->where('type', 'credits')->where('actionLabel', 'Receive'));
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

it('sorts the list by a column and direction', function () {
    Transaction::create(['transaction_date' => today()->toDateString(), 'invoice_no' => 'A', 'customer_id' => $this->customer->id, 'payment_method_id' => $this->cash->id, 'customs_fees' => 100, 'gov_fees' => 0, 'profit' => 10, 'vat_rate' => 0]);
    Transaction::create(['transaction_date' => today()->toDateString(), 'invoice_no' => 'B', 'customer_id' => $this->customer->id, 'payment_method_id' => $this->cash->id, 'customs_fees' => 900, 'gov_fees' => 0, 'profit' => 10, 'vat_rate' => 0]);

    // grand_total ascending → the 110 row (invoice A) comes first
    $this->actingAs($this->admin)->get(route('operations.index', ['sort' => 'grand_total', 'dir' => 'asc']))
        ->assertInertia(fn ($p) => $p
            ->where('sort.by', 'grand_total')->where('sort.dir', 'asc')
            ->where('rows.data.0.cells.1', 'A'));
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

it('splits commissions into Com-1 and Com-2 columns and totals', function () {
    $tx = opTx(today()->toDateString());
    $tx->commissions()->createMany([
        ['label' => 'Com-1', 'amount' => 30, 'type' => 'charged_to_customer'],
        ['label' => 'Com-2', 'amount' => 20, 'type' => 'charged_to_customer'],
    ]);

    $this->actingAs($this->admin)->get(route('operations.index'))
        ->assertInertia(fn ($p) => $p
            ->where('columns.12', 'Com-1')
            ->where('columns.13', 'Com-2')
            ->where('rows.data.0.cells.12', '30') // Com-1
            ->where('rows.data.0.cells.13', '20') // Com-2
            ->where('totals.12', '30')
            ->where('totals.13', '20')
            ->etc());
});

it('folds a third commission into the Com-2 total so it reconciles', function () {
    $tx = opTx(today()->toDateString());
    $tx->commissions()->createMany([
        ['label' => 'Com-1', 'amount' => 30, 'type' => 'charged_to_customer'],
        ['label' => 'Com-2', 'amount' => 20, 'type' => 'charged_to_customer'],
        ['label' => 'Com-3', 'amount' => 5, 'type' => 'charged_to_customer'],
    ]);

    $this->actingAs($this->admin)->get(route('operations.index'))
        ->assertInertia(fn ($p) => $p
            ->where('rows.data.0.cells.12', '30') // Com-1
            ->where('rows.data.0.cells.13', '25') // Com-2 = 20 + 5
            ->where('totals.12', '30')
            ->where('totals.13', '25')
            ->etc());
});

it('searches transactions by reference, not just customer/invoice', function () {
    $ref = App\Models\Reference::create(['name' => 'ZEBRA-REF']);
    $match = opTx(today()->toDateString());
    $match->update(['reference_id' => $ref->id]);
    opTx(today()->toDateString()); // a non-matching row

    $this->actingAs($this->admin)->get(route('operations.index', ['search' => 'ZEBRA']))
        ->assertInertia(fn ($p) => $p->where('rows.data', fn ($rows) => count($rows) === 1
            && $rows[0]['id'] === $match->id));
});

it('places commissions by their Com-1/Com-2 label, not by order', function () {
    // Excel had Com-1 empty and Com-2 = 25 → importer stores a single 'Com-2' row.
    $tx = opTx(today()->toDateString());
    $tx->commissions()->create(['label' => 'Com-2', 'amount' => 25, 'type' => 'charged_to_customer']);

    $this->actingAs($this->admin)->get(route('operations.index'))
        ->assertInertia(fn ($p) => $p
            ->where('rows.data.0.cells.12', '0')   // Com-1 stays empty
            ->where('rows.data.0.cells.13', '25')  // Com-2 keeps its value
            ->where('totals.12', '0')
            ->where('totals.13', '25')
            ->etc());
});

it('shows contact numbers on the invoices and credits tabs', function () {
    $tx = opTx(today()->toDateString());
    $tx->update(['contact_numbers' => ['050-111', '050-222'], 'credit_amount' => 50]);

    $this->actingAs($this->admin)->get(route('operations.index', ['type' => 'invoices']))
        ->assertInertia(fn ($p) => $p
            ->where('columns.3', 'Contact')
            ->where('rows.data.0.cells.3', '050-111, 050-222')
            ->etc());

    $this->actingAs($this->admin)->get(route('operations.index', ['type' => 'credits']))
        ->assertInertia(fn ($p) => $p
            ->where('columns.4', 'Contact')
            ->where('rows.data.0.cells.4', '050-111, 050-222')
            ->etc());
});
