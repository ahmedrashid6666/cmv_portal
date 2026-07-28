<?php

use App\Enums\Role;
use App\Models\Bank;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\BankService;

beforeEach(function () {
    $this->cdr = Bank::create(['name' => 'CDR', 'account_no' => '1', 'opening_balance' => 1000]);
    $this->rak = Bank::create(['name' => 'RAK', 'account_no' => '2', 'opening_balance' => 500]);
});

it('drains customs from the CDR bank and gov from the chosen bank', function () {
    // customs 200 (→ CDR), gov 80 assigned to RAK
    Transaction::factory()->create(['customs_fees' => 200, 'gov_fees' => 80, 'gov_bank_id' => $this->rak->id]);
    // another shipment: customs 50 (→ CDR), gov 30 with NO bank (unassigned)
    Transaction::factory()->create(['customs_fees' => 50, 'gov_fees' => 30, 'gov_bank_id' => null]);

    $balances = collect(app(BankService::class)->balances())->keyBy('name');

    expect((float) $balances['CDR']['customs_paid'])->toBe(250.0)   // 200 + 50
        ->and((float) $balances['CDR']['gov_paid'])->toBe(0.0)
        ->and((float) $balances['CDR']['balance'])->toBe(750.0)     // 1000 − 250
        ->and((float) $balances['RAK']['gov_paid'])->toBe(80.0)
        ->and((float) $balances['RAK']['balance'])->toBe(420.0);    // 500 − 80
});

it('reduces the combined bank balance by customs and assigned gov fees', function () {
    Transaction::factory()->create(['customs_fees' => 200, 'gov_fees' => 80, 'gov_bank_id' => $this->rak->id, 'payment_method_id' => PaymentMethod::factory()->create(['type' => 'cash'])->id]);

    // opening 1500 (1000 + 500) − customs 200 − gov 80 = 1220
    expect((float) app(BalanceService::class)->bankBalance())->toBe(1220.0);
});

it('resolves CDR as the customs bank and flags it', function () {
    $rows = collect(app(BankService::class)->balances());
    expect($rows->firstWhere('name', 'CDR')['is_customs'])->toBeTrue()
        ->and($rows->firstWhere('name', 'RAK')['is_customs'])->toBeFalse();
});

it('stores the chosen gov bank when a transaction is saved', function () {
    $method = PaymentMethod::factory()->create(['type' => 'cash']);
    $customer = \App\Models\Customer::factory()->create();

    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->post(route('transactions.store'), [
            'transaction_date' => '2026-07-28', 'customer_id' => $customer->id,
            'customs_fees' => 100, 'gov_fees' => 40, 'gov_bank_id' => $this->rak->id,
            'profit' => 25, 'vat_rate' => 0, 'payment_method_id' => $method->id,
        ])->assertRedirect();

    expect((int) Transaction::first()->gov_bank_id)->toBe($this->rak->id);
});

it('shows the per-bank statement with customs disbursements', function () {
    Transaction::factory()->create(['customs_fees' => 120, 'gov_fees' => 0, 'invoice_no' => 'INV-CS']);

    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->get(route('bank-accounts.statement', $this->cdr->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('statement.opening', 1000)->where('statement.closing', 880));
});

it('builds a bank-wise report', function () {
    Transaction::factory()->create(['customs_fees' => 100, 'gov_fees' => 0]);

    $report = app(\App\Services\ReportBuilder::class)->build('bank', []);
    expect($report['type'])->toBe('bank')
        ->and($report['totals']['Customs Paid'])->toBe(100.0);
});
