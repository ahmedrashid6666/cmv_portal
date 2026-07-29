<?php

use App\Enums\Role;
use App\Models\Bank;
use App\Models\BankEntry;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\BankService;

beforeEach(function () {
    $this->rak = Bank::create(['name' => 'RAK', 'account_no' => '2', 'opening_balance' => 500]);
});

it('raises a bank balance with an In entry and lowers it with an Out entry', function () {
    BankEntry::create(['bank_id' => $this->rak->id, 'entry_date' => '2026-07-29', 'item' => 'Cash deposit', 'direction' => 'in', 'amount' => 300]);
    BankEntry::create(['bank_id' => $this->rak->id, 'entry_date' => '2026-07-29', 'item' => 'Bank charge', 'direction' => 'out', 'amount' => 50]);

    $row = collect(app(BankService::class)->balances())->firstWhere('name', 'RAK');

    // 500 + 300 − 50 = 750
    expect((float) $row['balance'])->toBe(750.0)
        ->and((float) $row['total_in'])->toBe(300.0)
        ->and((float) $row['total_out'])->toBe(50.0);
});

it('reflects net In/Out in the combined dashboard bank balance', function () {
    BankEntry::create(['bank_id' => $this->rak->id, 'entry_date' => '2026-07-29', 'item' => 'Deposit', 'direction' => 'in', 'amount' => 200]);
    BankEntry::create(['bank_id' => $this->rak->id, 'entry_date' => '2026-07-29', 'item' => 'Fee', 'direction' => 'out', 'amount' => 75]);

    // opening 500 + 200 − 75 = 625
    expect((float) app(BalanceService::class)->bankBalance())->toBe(625.0);
});

it('stores an In entry from the form and records the creator', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();

    $this->actingAs($admin)
        ->from(route('bank-accounts.index'))
        ->post(route('bank-accounts.entries.store', $this->rak->id), [
            'entry_date' => '2026-07-29', 'item' => 'Cash deposit', 'description' => 'Branch drop', 'in' => 300, 'out' => '',
        ])
        ->assertRedirect(route('bank-accounts.index'));

    $entry = BankEntry::sole();
    expect($entry->direction)->toBe('in')
        ->and((float) $entry->amount)->toBe(300.0)
        ->and($entry->created_by)->toBe($admin->id);
});

it('rejects an entry with neither In nor Out', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();

    $this->actingAs($admin)
        ->from(route('bank-accounts.index'))
        ->post(route('bank-accounts.entries.store', $this->rak->id), [
            'entry_date' => '2026-07-29', 'item' => 'Nothing', 'in' => '', 'out' => '',
        ])
        ->assertSessionHasErrors('amount');

    expect(BankEntry::count())->toBe(0);
});

it('rejects an entry with both In and Out filled', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();

    $this->actingAs($admin)
        ->from(route('bank-accounts.index'))
        ->post(route('bank-accounts.entries.store', $this->rak->id), [
            'entry_date' => '2026-07-29', 'item' => 'Both', 'in' => 100, 'out' => 50,
        ])
        ->assertSessionHasErrors('amount');

    expect(BankEntry::count())->toBe(0);
});

it('deletes an entry, reverting its effect on the balance', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();
    $entry = BankEntry::create(['bank_id' => $this->rak->id, 'entry_date' => '2026-07-29', 'item' => 'Deposit', 'direction' => 'in', 'amount' => 300]);

    $this->actingAs($admin)
        ->from(route('bank-accounts.index'))
        ->delete(route('bank-accounts.entries.destroy', $entry->id))
        ->assertRedirect(route('bank-accounts.index'));

    $row = collect(app(BankService::class)->balances())->firstWhere('name', 'RAK');
    expect((float) $row['balance'])->toBe(500.0); // back to opening
});

it('includes In/Out entries in the statement so its closing reconciles with the balance', function () {
    BankEntry::create(['bank_id' => $this->rak->id, 'entry_date' => '2026-07-10', 'item' => 'Deposit', 'direction' => 'in', 'amount' => 300]);
    BankEntry::create(['bank_id' => $this->rak->id, 'entry_date' => '2026-07-12', 'item' => 'Charge', 'direction' => 'out', 'amount' => 50]);

    $statement = app(BankService::class)->statement($this->rak);
    $accountsBalance = collect(app(BankService::class)->balances())->firstWhere('name', 'RAK')['balance'];

    // 500 + 300 − 50 = 750, and the statement closing must match the Accounts page.
    expect((float) $statement['closing'])->toBe(750.0)
        ->and((float) $statement['closing'])->toBe((float) $accountsBalance);
});

it('passes each bank its In/Out entries to the accounts page', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();
    BankEntry::create(['bank_id' => $this->rak->id, 'entry_date' => '2026-07-29', 'item' => 'Deposit', 'description' => 'Drop', 'direction' => 'in', 'amount' => 300]);

    $this->actingAs($admin)->get(route('bank-accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Banks/Accounts')
            ->has('banks.0.entries', 1)
            ->where('banks.0.entries.0.item', 'Deposit')
            ->where('banks.0.entries.0.direction', 'in')
            ->where('banks.0.entries.0.amount', 300)
        );
});

it('forbids a read-only user from adding an entry', function () {
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('bank-accounts.entries.store', $this->rak->id), [
            'entry_date' => '2026-07-29', 'item' => 'Deposit', 'in' => 100,
        ])
        ->assertForbidden();

    expect(BankEntry::count())->toBe(0);
});
