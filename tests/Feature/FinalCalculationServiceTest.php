<?php

use App\Models\Bank;
use App\Models\BankEntry;
use App\Models\CashCount;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\FinalCalculationService;

beforeEach(fn () => $this->service = app(FinalCalculationService::class));

it('sums shipment profit — not net profit — into total_income, date-scoped', function () {
    Transaction::factory()->create(['transaction_date' => '2026-07-01', 'profit' => 100, 'net_profit' => 40]);
    Transaction::factory()->create(['transaction_date' => '2026-07-05', 'profit' => 50, 'net_profit' => 10]);
    Transaction::factory()->create(['transaction_date' => '2026-08-01', 'profit' => 999, 'net_profit' => 999]); // after the cutoff

    $data = $this->service->defaults('2026-07-05');

    expect($data['total_income'])->toBe(150.0);
});

it('sums customs and gov fees paid, date-scoped', function () {
    Transaction::factory()->create(['transaction_date' => '2026-07-01', 'customs_fees' => 75, 'gov_fees' => 25]);
    Transaction::factory()->create(['transaction_date' => '2026-08-01', 'customs_fees' => 500, 'gov_fees' => 500]); // after cutoff

    $data = $this->service->defaults('2026-07-01');

    expect($data['customs_gov_fees'])->toBe(100.0);
});

it('splits raw bank balances (opening + entries only) between all banks and the CDR bank', function () {
    $rak = Bank::create(['name' => 'RAK', 'account_no' => '1', 'opening_balance' => 1000]);
    $cdr = Bank::create(['name' => 'CDR', 'account_no' => '2', 'opening_balance' => 500]);
    Setting::put('customs_bank_id', $cdr->id);

    BankEntry::create(['bank_id' => $rak->id, 'entry_date' => '2026-07-01', 'item' => 'Deposit', 'direction' => 'in', 'amount' => 300]);
    BankEntry::create(['bank_id' => $cdr->id, 'entry_date' => '2026-07-01', 'item' => 'Charge', 'direction' => 'out', 'amount' => 200]);

    // A customs fee that BankService::balances() would net out of the CDR
    // balance — rawBankBalances() must ignore it entirely.
    Transaction::factory()->create(['transaction_date' => '2026-07-01', 'customs_fees' => 9999]);

    $data = $this->service->defaults('2026-07-01');

    expect($data['bank_ac_balance'])->toBe(1300.0)   // 1000 + 300
        ->and($data['cdr_ac_balance'])->toBe(300.0);  // 500 - 200, customs fee NOT subtracted
});

it('reads borrowed and daily-credit pending totals live, not scoped to the date', function () {
    LedgerEntry::factory()->borrowed()->create(['total_amount' => 5000, 'paid_amount' => 0]);
    LedgerEntry::factory()->create(['total_amount' => 2000, 'paid_amount' => 500]); // daily_credit (factory default type)

    $data = $this->service->defaults('2020-01-01'); // long before either entry

    expect($data['borrowed_amount'])->toBe(5000.0)
        ->and($data['daily_credit_pending'])->toBe(1500.0);
});

it("pulls the counted cash from that date's saved Daily Cash Count", function () {
    CashCount::create(['count_date' => '2026-07-01', 'lines' => ['AED' => [], 'OMR' => []], 'total_aed' => 321.5, 'total_omr' => 12.3]);

    $data = $this->service->defaults('2026-07-01');

    expect($data['aed_counted'])->toBe(321.5)
        ->and($data['omr_counted'])->toBe(12.3);
});

it('liquidCashFor returns the computed Total Cash Balance In Hand', function () {
    Setting::put('cash_opening_balance', 1000);

    expect($this->service->liquidCashFor('2026-07-01'))->toBe(1000.0);
});

it('withLiveCashCount overlays counted cash without touching other fields', function () {
    CashCount::create(['count_date' => '2026-07-01', 'lines' => ['AED' => [], 'OMR' => []], 'total_aed' => 50, 'total_omr' => 5]);

    $data = $this->service->withLiveCashCount(['opening_balance' => 999, 'aed_counted' => 0, 'omr_counted' => 0], '2026-07-01');

    expect($data['opening_balance'])->toBe(999)
        ->and($data['aed_counted'])->toBe(50.0)
        ->and($data['omr_counted'])->toBe(5.0);
});
