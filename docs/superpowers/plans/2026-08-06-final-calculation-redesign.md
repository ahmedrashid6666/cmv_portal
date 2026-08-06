# Final Calculation Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Final Calculation page's free-form row-grid worksheet with the accountant's fixed spreadsheet ladder (Opening Balance → Total Cash Balance In Hand), and relocate the AED/OMR cash-counting UI there from the standalone Daily Cash Count page.

**Architecture:** `FinalCalculationService::compute()`/`defaults()` are rewritten around a fixed named-field payload instead of a `rows` array. A new client-side mirror (`computeFinalCalculation` in `resources/js/lib/calc.js`) keeps on-screen totals in sync with the server, matching the existing `computeTotals`/`TransactionCalculator` pattern. The AED/OMR denomination + bundle widgets move into `Books/FinalCalculation/Index.jsx` as their own embedded form that still POSTs to the existing `cash-count.store` route — the `CashCount` model, its controller, and its route are untouched.

**Tech Stack:** Laravel 11 (PHP 8.3+), Pest, Inertia + React, Tailwind, Vitest, DomPDF.

## Global Constraints

- No database migration — `final_calculations.data` is already a JSON column; existing decimal columns (`total_amount`, `liquid_cash`, `cash_counted`, `cash_extra`) are reused with updated meaning, per the approved design spec.
- `BankService::balances()` and every page that consumes it (Bank Accounts, Dashboard, statements) must be untouched — the new bank/CDR split for this page is a separate, page-local computation.
- `CashCountController`, `CashCount` model, and the `cash-count.*` routes are untouched.
- Total Income = Σ `Transaction.profit` (not `net_profit`); per-shipment expenses and reference commissions are excluded from this page's math entirely.
- Both pages that read/write a `CashCount` record must always round-trip its full four fields (`lines`, `extras`, `bundles`, `remarks`) even where a page only renders a subset of them — otherwise saving from one page silently blanks out fields edited on the other.
- Reference spec: `docs/superpowers/specs/2026-08-06-final-calculation-redesign-design.md`.

---

### Task 1: Rewrite `FinalCalculationService`

**Files:**
- Modify: `app/Services/FinalCalculationService.php` (full rewrite)
- Test: `tests/Unit/FinalCalculationComputeTest.php` (full rewrite — pure `compute()`, no DB)
- Test: `tests/Feature/FinalCalculationServiceTest.php` (new — `defaults()`/`rawBankBalances()`/`liquidCashFor()`/`withLiveCashCount()`, needs DB)

**Interfaces:**
- Produces: `FinalCalculationService::compute(array $data): array` returning keys `opening_balance, total_income, customs_gov_fees, credit_unpaid, office_expenses, total_amount, borrowed_amount, daily_credit_pending, total_balance_amount, bank_ac_balance, cdr_ac_balance, total_cash_balance, aed_counted, omr_counted, omr_rate, cash_counted, cash_extra` (all floats).
- Produces: `FinalCalculationService::defaults(string $date): array` — same field names (minus the four `total_*`/`cash_*` computed ones), plus `remarks => null`. Consumed by `FinalCalculationController` (Task 2).
- Produces: `FinalCalculationService::liquidCashFor(string $date): float` (unchanged signature, now returns `total_cash_balance`). Consumed by `CashCountController::index()` (unchanged caller).
- Produces: `FinalCalculationService::withLiveCashCount(array $data, string $date): array` (unchanged signature). Consumed by `FinalCalculationController::index()` (Task 2).
- Produces: `FinalCalculationService::omrRate(): float` (unchanged, untouched).
- Removes: `coreRows()`, `withDwsCashDefault()`, `carriedManualRows()`, `row()` — no longer needed, the row-grid concept is gone.

- [ ] **Step 1: Write the failing unit test for `compute()`**

Replace the entire contents of `tests/Unit/FinalCalculationComputeTest.php`:

```php
<?php

use App\Services\FinalCalculationService;

/**
 * Locks the reconciliation math to the accountant's spreadsheet screenshot:
 * Opening Balance -> Total Income -> ... -> Total Cash Balance In Hand.
 */
it('reproduces the spreadsheet totals exactly', function () {
    $data = [
        'opening_balance' => 64061,
        'total_income' => 15793,
        'customs_gov_fees' => 11688,
        'credit_unpaid' => 8850,
        'office_expenses' => 2434,
        'borrowed_amount' => 89700,
        'daily_credit_pending' => 58069,
        'bank_ac_balance' => 56684,
        'cdr_ac_balance' => 19927,
        'aed_counted' => 0,
        'omr_counted' => 0,
        'omr_rate' => 9.5238,
    ];

    $t = app(FinalCalculationService::class)->compute($data);

    expect($t['total_amount'])->toBe(56882.0)
        ->and($t['total_balance_amount'])->toBe(88513.0)
        ->and($t['total_cash_balance'])->toBe(11902.0)
        ->and($t['cash_counted'])->toBe(0.0)
        ->and($t['cash_extra'])->toBe(-11902.0);
});

it('converts OMR counted cash to AED at the given rate', function () {
    $t = app(FinalCalculationService::class)->compute([
        'opening_balance' => 0, 'total_income' => 0, 'customs_gov_fees' => 0,
        'credit_unpaid' => 0, 'office_expenses' => 0, 'borrowed_amount' => 0,
        'daily_credit_pending' => 0, 'bank_ac_balance' => 0, 'cdr_ac_balance' => 0,
        'aed_counted' => 100, 'omr_counted' => 10, 'omr_rate' => 9.5,
    ]);

    expect($t['cash_counted'])->toBe(195.0)  // 100 + 10 * 9.5
        ->and($t['total_cash_balance'])->toBe(0.0)
        ->and($t['cash_extra'])->toBe(195.0);
});

it('treats missing fields as zero and defaults the OMR rate', function () {
    $t = app(FinalCalculationService::class)->compute([]);

    expect($t['total_amount'])->toBe(0.0)
        ->and($t['total_balance_amount'])->toBe(0.0)
        ->and($t['total_cash_balance'])->toBe(0.0)
        ->and($t['omr_rate'])->toBe(FinalCalculationService::DEFAULT_OMR_RATE)
        ->and($t['cash_extra'])->toBe(0.0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FinalCalculationComputeTest`
Expected: FAIL — the old `compute()` still expects a `rows` array and returns different keys (`total_ac_balance`, `liquid_cash`, …), so `$t['total_balance_amount']` etc. will be undefined-index errors.

- [ ] **Step 3: Rewrite `FinalCalculationService`**

Replace the entire contents of `app/Services/FinalCalculationService.php`:

```php
<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\BankEntry;
use App\Models\CashCount;
use App\Models\FinalCalculation;
use App\Models\LedgerEntry;
use App\Models\OfficeExpense;
use App\Models\Setting;
use App\Models\Transaction;

/**
 * Builds and evaluates the "Final Calculation" reconciliation worksheet — a
 * fixed ladder of figures mirroring the accountant's spreadsheet:
 *
 *   Opening Balance + Total Income - Customs/Gov Fees - Credit Unpaid - Office Expenses
 *     = Total Amount
 *   + Borrowed Amount - Daily Credit (Pending) = Total Balance Amount
 *   - All Bank A/C Balance - CDR A/C Balance = Total Cash Balance In Hand
 *
 * compute() runs the formulas above on a plain array of inputs (server-side on
 * save, and mirrored client-side in resources/js/lib/calc.js so on-screen
 * totals match the saved snapshot). defaults() builds those inputs live for a
 * given date.
 */
class FinalCalculationService
{
    public const DEFAULT_OMR_RATE = 9.5238;

    public function __construct(private BalanceService $balances, private BankService $banks) {}

    /**
     * Evaluate the reconciliation totals from a data payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, float>
     */
    public function compute(array $data): array
    {
        $rate = (float) ($data['omr_rate'] ?? self::DEFAULT_OMR_RATE);

        $openingBalance = round((float) ($data['opening_balance'] ?? 0), 2);
        $totalIncome = round((float) ($data['total_income'] ?? 0), 2);
        $customsGovFees = round((float) ($data['customs_gov_fees'] ?? 0), 2);
        $creditUnpaid = round((float) ($data['credit_unpaid'] ?? 0), 2);
        $officeExpenses = round((float) ($data['office_expenses'] ?? 0), 2);
        $totalAmount = round($openingBalance + $totalIncome - $customsGovFees - $creditUnpaid - $officeExpenses, 2);

        $borrowedAmount = round((float) ($data['borrowed_amount'] ?? 0), 2);
        $dailyCreditPending = round((float) ($data['daily_credit_pending'] ?? 0), 2);
        $totalBalanceAmount = round($totalAmount + $borrowedAmount - $dailyCreditPending, 2);

        $bankAcBalance = round((float) ($data['bank_ac_balance'] ?? 0), 2);
        $cdrAcBalance = round((float) ($data['cdr_ac_balance'] ?? 0), 2);
        $totalCashBalance = round($totalBalanceAmount - $bankAcBalance - $cdrAcBalance, 2);

        $aedCounted = round((float) ($data['aed_counted'] ?? 0), 2);
        $omrCounted = round((float) ($data['omr_counted'] ?? 0), 3);
        $cashCounted = round($aedCounted + $omrCounted * $rate, 2);
        $cashExtra = round($cashCounted - $totalCashBalance, 2);

        return [
            'opening_balance' => $openingBalance,
            'total_income' => $totalIncome,
            'customs_gov_fees' => $customsGovFees,
            'credit_unpaid' => $creditUnpaid,
            'office_expenses' => $officeExpenses,
            'total_amount' => $totalAmount,
            'borrowed_amount' => $borrowedAmount,
            'daily_credit_pending' => $dailyCreditPending,
            'total_balance_amount' => $totalBalanceAmount,
            'bank_ac_balance' => $bankAcBalance,
            'cdr_ac_balance' => $cdrAcBalance,
            'total_cash_balance' => $totalCashBalance,
            'aed_counted' => $aedCounted,
            'omr_counted' => $omrCounted,
            'omr_rate' => $rate,
            'cash_counted' => $cashCounted,
            'cash_extra' => $cashExtra,
        ];
    }

    /**
     * The Total Cash Balance In Hand figure for a date — the saved snapshot's
     * total if one exists, otherwise the live-computed default. Used as the
     * "Expected Cash" baseline on the Daily Cash Count page, so both screens
     * reconcile against the same number.
     */
    public function liquidCashFor(string $date): float
    {
        $snapshot = FinalCalculation::whereDate('calc_date', $date)->first();
        $data = $snapshot ? $snapshot->data : $this->defaults($date);

        return $this->compute($data)['total_cash_balance'];
    }

    /** The app-wide OMR → AED rate, editable in Settings. */
    public function omrRate(): float
    {
        return (float) Setting::get('omr_to_aed_rate', self::DEFAULT_OMR_RATE);
    }

    /**
     * Live-computed worksheet inputs for a date — every figure cumulative
     * through $date except Borrowed Amount, Daily Credit (Pending), and the
     * bank balances, which are current live totals.
     *
     * @return array<string, mixed>
     */
    public function defaults(string $date): array
    {
        [$bankAcBalance, $cdrAcBalance] = $this->rawBankBalances();

        return array_merge([
            'opening_balance' => (float) Setting::get('cash_opening_balance', 0),
            'total_income' => round((float) Transaction::whereDate('transaction_date', '<=', $date)->sum('profit'), 2),
            'customs_gov_fees' => round(
                (float) Transaction::whereDate('transaction_date', '<=', $date)->sum('customs_fees')
                + (float) Transaction::whereDate('transaction_date', '<=', $date)->sum('gov_fees'),
                2,
            ),
            'credit_unpaid' => (float) $this->balances->creditOutstandingAsOf($date),
            'office_expenses' => round((float) OfficeExpense::whereDate('expense_date', '<=', $date)->sum('amount'), 2),
            'borrowed_amount' => $this->ledgerOutstanding(LedgerEntry::TYPE_BORROWED),
            'daily_credit_pending' => $this->ledgerOutstanding(LedgerEntry::TYPE_CREDIT),
            'bank_ac_balance' => $bankAcBalance,
            'cdr_ac_balance' => $cdrAcBalance,
            'omr_rate' => $this->omrRate(),
            'remarks' => null,
        ], $this->countTotalsFor($date));
    }

    /**
     * Overlay a data payload's counted-cash cells with the date's actual
     * saved Daily Cash Count, whether $data came from a live default or an
     * already-saved snapshot. Without this, saving a Final Calculation
     * before the day's cash count is entered (or re-counted) freezes a
     * stale/zero figure that a later Cash Count save never reaches.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withLiveCashCount(array $data, string $date): array
    {
        return array_merge($data, $this->countTotalsFor($date));
    }

    /** @return array{aed_counted: float, omr_counted: float} */
    private function countTotalsFor(string $date): array
    {
        $count = CashCount::whereDate('count_date', $date)->first();

        return [
            'aed_counted' => $count ? (float) $count->total_aed : 0.0,
            'omr_counted' => $count ? (float) $count->total_omr : 0.0,
        ];
    }

    /**
     * Every bank's raw position — opening balance plus manual BankEntry
     * in/out movements only, deliberately *not* netting out customs/gov/
     * office fees the way BankService::balances() does for its own callers
     * (Bank Accounts page, dashboard, statements). Those fees are already
     * subtracted once via the "Total Customs/Gov Fees Paid" row above, so
     * re-netting them here would double-count them against Total Cash
     * Balance In Hand.
     *
     * @return array{0: float, 1: float} [allBankTotal, cdrTotal]
     */
    private function rawBankBalances(): array
    {
        $customsBankId = $this->banks->customsBank()?->id;
        $entriesIn = BankEntry::where('direction', 'in')->selectRaw('bank_id, SUM(amount) as total')->groupBy('bank_id')->pluck('total', 'bank_id');
        $entriesOut = BankEntry::where('direction', 'out')->selectRaw('bank_id, SUM(amount) as total')->groupBy('bank_id')->pluck('total', 'bank_id');

        $bankTotal = 0.0;
        $cdrTotal = 0.0;

        foreach (Bank::all() as $bank) {
            $raw = (float) $bank->opening_balance
                + (float) ($entriesIn[$bank->id] ?? 0)
                - (float) ($entriesOut[$bank->id] ?? 0);

            if ($bank->id === $customsBankId) {
                $cdrTotal += $raw;
            } else {
                $bankTotal += $raw;
            }
        }

        return [round($bankTotal, 2), round($cdrTotal, 2)];
    }

    private function ledgerOutstanding(string $type): float
    {
        return round((float) LedgerEntry::ofType($type)
            ->where('status', '!=', 'returned')
            ->sum('balance_amount'), 2);
    }
}
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `php artisan test --filter=FinalCalculationComputeTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Write the failing feature test for `defaults()` and friends**

Create `tests/Feature/FinalCalculationServiceTest.php`:

```php
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
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `php artisan test --filter=FinalCalculationServiceTest`
Expected: FAIL — the old `defaults()` still returns a `rows` array shape, so `$data['total_income']` etc. are undefined.

(Note: Step 3 already rewrote the service, so this should actually PASS once Step 3 is done — if executing tasks strictly in order, run this step's test immediately after Step 3's rewrite is in place, before writing further code. If it fails, the rewrite in Step 3 has a bug — fix `FinalCalculationService` until it passes, do not add new code elsewhere to compensate.)

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=FinalCalculationServiceTest`
Expected: PASS (7 tests)

- [ ] **Step 8: Run the full existing suite to check for regressions**

Run: `php artisan test --filter=FinalCalculationTest` and `php artisan test --filter=CashCountTest`
Expected: `FinalCalculationTest` FAILS (it still posts/asserts the old `rows` shape — rewritten in Task 2). `CashCountTest` PASSES unchanged — `CashCountController` never called the removed methods.

- [ ] **Step 9: Commit**

```bash
git add app/Services/FinalCalculationService.php tests/Unit/FinalCalculationComputeTest.php tests/Feature/FinalCalculationServiceTest.php
git commit -m "refactor: rewrite FinalCalculationService around the fixed spreadsheet breakdown"
```

---

### Task 2: Rewrite `FinalCalculationController` and its feature test

**Files:**
- Modify: `app/Http/Controllers/FinalCalculationController.php`
- Test: `tests/Feature/FinalCalculationTest.php` (full rewrite)

**Interfaces:**
- Consumes: `FinalCalculationService::defaults()`, `compute()`, `withLiveCashCount()` from Task 1 (exact field names as returned by `compute()`).
- Produces: `Books/FinalCalculation/Index` Inertia props `{ date, data, totals, saved, savedId, defaultOmrRate, denominations, count, history }` — consumed by the frontend in Task 4. `count` is `null` or `{ lines, extras, bundles, remarks }` (the full `CashCount` shape, not just `lines`/`bundles` — needed so the embedded cash-count form round-trips fields it doesn't render, see Global Constraints).
- Produces: `final-calc.store` accepting `{ calc_date, data: { opening_balance, total_income, customs_gov_fees, credit_unpaid, office_expenses, borrowed_amount, daily_credit_pending, bank_ac_balance, cdr_ac_balance, aed_counted?, omr_counted?, omr_rate? }, remarks? }`.

- [ ] **Step 1: Write the failing feature test**

Replace the entire contents of `tests/Feature/FinalCalculationTest.php`:

```php
<?php

use App\Enums\Role;
use App\Models\CashCount;
use App\Models\FinalCalculation;
use App\Models\User;

beforeEach(fn () => $this->actor = User::factory()->role(Role::ACCOUNTANT)->create());

$payload = fn (string $date = '2026-07-01') => [
    'calc_date' => $date,
    'data' => [
        'opening_balance' => 64061, 'total_income' => 15793, 'customs_gov_fees' => 11688,
        'credit_unpaid' => 8850, 'office_expenses' => 2434,
        'borrowed_amount' => 89700, 'daily_credit_pending' => 58069,
        'bank_ac_balance' => 56684, 'cdr_ac_balance' => 19927,
        'aed_counted' => 11000, 'omr_counted' => 0, 'omr_rate' => 9.5238,
    ],
    'remarks' => 'July close',
];

it('shows the page with live-computed defaults when no snapshot exists', function () {
    $this->actingAs($this->actor)->get(route('final-calc.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('data.opening_balance')->has('totals.total_cash_balance')
            ->has('denominations')->where('saved', false));
});

it('saves a snapshot and stores the computed totals', function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();

    $c = FinalCalculation::first();
    expect((float) $c->total_amount)->toBe(56882.0)
        ->and((float) $c->liquid_cash)->toBe(11902.0)   // Total Cash Balance In Hand
        ->and((float) $c->cash_counted)->toBe(11000.0)
        ->and((float) $c->cash_extra)->toBe(-902.0)      // 11000 - 11902
        ->and($c->remarks)->toBe('July close');
});

it('upserts one snapshot per date', function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();
    expect(FinalCalculation::count())->toBe(1);
});

it('reopens a saved date with its frozen figures', function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();

    $this->actingAs($this->actor)->get(route('final-calc.index', ['date' => '2026-07-01']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('saved', true)->where('totals.total_cash_balance', fn ($v) => (float) $v === 11902.0));
});

it("overlays the frozen snapshot's counted cash with a cash count saved afterwards", function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();

    CashCount::create(['count_date' => '2026-07-01', 'lines' => ['AED' => [], 'OMR' => []], 'total_aed' => 12500, 'total_omr' => 0]);

    $this->actingAs($this->actor)->get(route('final-calc.index', ['date' => '2026-07-01']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('data.aed_counted', 12500.0));
});

it('passes the full CashCount fields through so the embedded widget round-trips extras/remarks', function () {
    CashCount::create([
        'count_date' => '2026-07-01',
        'lines' => ['AED' => ['1000' => 2], 'OMR' => []],
        'extras' => ['AED' => [['label' => 'Petty cash out', 'amount' => 50]], 'OMR' => []],
        'bundles' => ['AED' => [], 'OMR' => []],
        'remarks' => 'Counted twice to confirm',
        'total_aed' => 2000, 'total_omr' => 0,
    ]);

    $this->actingAs($this->actor)->get(route('final-calc.index', ['date' => '2026-07-01']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('count.extras.AED.0.label', 'Petty cash out')
            ->where('count.remarks', 'Counted twice to confirm'));
});

it('forbids read-only users from saving', function () use ($payload) {
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('final-calc.store'), $payload())
        ->assertForbidden();
});

it('exports a snapshot PDF', function () {
    $c = FinalCalculation::create([
        'calc_date' => '2026-07-01',
        'data' => ['opening_balance' => 100, 'omr_rate' => 9.5238],
        'total_amount' => 100, 'liquid_cash' => 100, 'cash_counted' => 0, 'cash_extra' => -100,
    ]);

    $this->actingAs($this->actor)->get(route('final-calc.pdf', $c))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FinalCalculationTest`
Expected: FAIL — the controller still renders/validates the old `data.rows` shape and doesn't pass `denominations`/`count`.

- [ ] **Step 3: Rewrite `FinalCalculationController`**

Replace the entire contents of `app/Http/Controllers/FinalCalculationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CashCount;
use App\Models\FinalCalculation;
use App\Services\FinalCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class FinalCalculationController extends Controller
{
    public function __construct(private FinalCalculationService $service) {}

    public function index(Request $request)
    {
        $date = $request->date('date')?->toDateString() ?? Carbon::today()->toDateString();
        $snapshot = FinalCalculation::whereDate('calc_date', $date)->first();

        // A saved day loads its frozen figures; a fresh day (or ?fresh=1, the
        // "recompute from live data" action) is auto-filled from live balances.
        $data = ($snapshot && ! $request->boolean('fresh'))
            ? $snapshot->data
            : $this->service->defaults($date);

        // The counted-cash cells always reflect the date's actual Daily Cash
        // Count, even for an already-saved snapshot — so a cash count
        // entered/updated after saving still shows up here.
        $data = $this->service->withLiveCashCount($data, $date);

        $count = CashCount::whereDate('count_date', $date)->first();

        return Inertia::render('Books/FinalCalculation/Index', [
            'date' => $date,
            'data' => $data,
            'totals' => $this->service->compute($data),
            'saved' => (bool) $snapshot,
            'savedId' => $snapshot?->id,
            'defaultOmrRate' => FinalCalculationService::DEFAULT_OMR_RATE,
            'denominations' => CashCount::DENOMINATIONS,
            // Full CashCount shape (not just lines/bundles) so the embedded
            // widget can resubmit extras/remarks unchanged even though it
            // doesn't render them — see Global Constraints.
            'count' => $count ? [
                'lines' => $count->lines,
                'extras' => $count->extras ?? ['AED' => [], 'OMR' => []],
                'bundles' => $count->bundles ?? ['AED' => [], 'OMR' => []],
                'remarks' => $count->remarks,
            ] : null,
            'history' => FinalCalculation::latest('calc_date')->limit(20)->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'date' => $c->calc_date->format('Y-m-d'),
                    'total_cash_balance' => (float) $c->liquid_cash,
                    'cash_extra' => (float) $c->cash_extra,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'calc_date' => ['required', 'date'],
            'data' => ['required', 'array'],
            'data.opening_balance' => ['required', 'numeric'],
            'data.total_income' => ['required', 'numeric'],
            'data.customs_gov_fees' => ['required', 'numeric'],
            'data.credit_unpaid' => ['required', 'numeric'],
            'data.office_expenses' => ['required', 'numeric'],
            'data.borrowed_amount' => ['required', 'numeric'],
            'data.daily_credit_pending' => ['required', 'numeric'],
            'data.bank_ac_balance' => ['required', 'numeric'],
            'data.cdr_ac_balance' => ['required', 'numeric'],
            'data.aed_counted' => ['nullable', 'numeric'],
            'data.omr_counted' => ['nullable', 'numeric'],
            'data.omr_rate' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $data = $validated['data'];
        $data['remarks'] = $validated['remarks'] ?? ($data['remarks'] ?? null);
        $totals = $this->service->compute($data);

        FinalCalculation::updateOrCreate(
            ['calc_date' => $validated['calc_date']],
            [
                'data' => $data,
                'total_amount' => $totals['total_amount'],
                'liquid_cash' => $totals['total_cash_balance'],
                'cash_counted' => $totals['cash_counted'],
                'cash_extra' => $totals['cash_extra'],
                'remarks' => $data['remarks'],
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Final calculation saved for '.$validated['calc_date'].'.');
    }

    public function destroy(FinalCalculation $finalCalculation)
    {
        $date = $finalCalculation->calc_date->format('Y-m-d');
        $finalCalculation->delete();

        return back()->with('success', 'Final calculation snapshot for '.$date.' deleted.');
    }

    public function pdf(FinalCalculation $finalCalculation)
    {
        return Pdf::loadView('final-calculation.pdf', [
            'calc' => $finalCalculation,
            'totals' => $this->service->compute($finalCalculation->data),
        ])->download('final-calculation-'.$finalCalculation->calc_date->format('Y-m-d').'.pdf');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=FinalCalculationTest`
Expected: PASS (8 tests) — the PDF test will fail here only if the Blade view errors on the old `data.rows` assumption; that's fixed in Task 6, so if this specific test fails with a Blade error, that's expected until Task 6 lands. All other 7 tests must pass now.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/FinalCalculationController.php tests/Feature/FinalCalculationTest.php
git commit -m "feat: rewrite FinalCalculationController for the fixed breakdown"
```

---

### Task 3: Add `computeFinalCalculation()` client-side mirror

**Files:**
- Modify: `resources/js/lib/calc.js` (add export, keep `computeTotals` unchanged)
- Test: `resources/js/lib/__tests__/calc.test.js` (add tests, keep existing ones)

**Interfaces:**
- Produces: `computeFinalCalculation(data: object): { total_amount, total_balance_amount, total_cash_balance, cash_counted, cash_extra }`. Consumed by `Books/FinalCalculation/Index.jsx` (Task 4).

- [ ] **Step 1: Write the failing test**

Append to `resources/js/lib/__tests__/calc.test.js` (add the import and the new `describe` block; keep the existing `computeTotals` import and tests as-is):

```js
import { describe, expect, it } from 'vitest';
import { computeFinalCalculation, computeTotals } from '../calc';

// ...existing `describe('computeTotals ...)` block stays unchanged below...

describe('computeFinalCalculation (mirror of FinalCalculationService::compute)', () => {
    it('reproduces the spreadsheet totals exactly', () => {
        const r = computeFinalCalculation({
            opening_balance: 64061, total_income: 15793, customs_gov_fees: 11688,
            credit_unpaid: 8850, office_expenses: 2434,
            borrowed_amount: 89700, daily_credit_pending: 58069,
            bank_ac_balance: 56684, cdr_ac_balance: 19927,
            aed_counted: 0, omr_counted: 0, omr_rate: 9.5238,
        });
        expect(r.total_amount).toBe(56882);
        expect(r.total_balance_amount).toBe(88513);
        expect(r.total_cash_balance).toBe(11902);
        expect(r.cash_extra).toBe(-11902);
    });

    it('converts OMR counted cash to AED at the given rate', () => {
        const r = computeFinalCalculation({ aed_counted: 100, omr_counted: 10, omr_rate: 9.5 });
        expect(r.cash_counted).toBe(195);
        expect(r.cash_extra).toBe(195);
    });

    it('treats missing fields as zero and defaults the OMR rate', () => {
        const r = computeFinalCalculation({});
        expect(r.total_amount).toBe(0);
        expect(r.total_cash_balance).toBe(0);
        expect(r.cash_extra).toBe(0);
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test`
Expected: FAIL — `computeFinalCalculation` is not exported from `../calc` yet.

- [ ] **Step 3: Add `computeFinalCalculation` to `resources/js/lib/calc.js`**

Append this export at the end of `resources/js/lib/calc.js` (the file already defines private helpers `n` and `r2` at the top — reuse them, don't redefine):

```js
// Mirrors app/Services/FinalCalculationService::compute() for instant on-screen totals.
export function computeFinalCalculation(data) {
    const rate = n(data.omr_rate) || 9.5238;

    const openingBalance = n(data.opening_balance);
    const totalIncome = n(data.total_income);
    const customsGovFees = n(data.customs_gov_fees);
    const creditUnpaid = n(data.credit_unpaid);
    const officeExpenses = n(data.office_expenses);
    const totalAmount = r2(openingBalance + totalIncome - customsGovFees - creditUnpaid - officeExpenses);

    const borrowedAmount = n(data.borrowed_amount);
    const dailyCreditPending = n(data.daily_credit_pending);
    const totalBalanceAmount = r2(totalAmount + borrowedAmount - dailyCreditPending);

    const bankAcBalance = n(data.bank_ac_balance);
    const cdrAcBalance = n(data.cdr_ac_balance);
    const totalCashBalance = r2(totalBalanceAmount - bankAcBalance - cdrAcBalance);

    const aedCounted = n(data.aed_counted);
    const omrCounted = n(data.omr_counted);
    const cashCounted = r2(aedCounted + omrCounted * rate);
    const cashExtra = r2(cashCounted - totalCashBalance);

    return {
        total_amount: totalAmount,
        total_balance_amount: totalBalanceAmount,
        total_cash_balance: totalCashBalance,
        cash_counted: cashCounted,
        cash_extra: cashExtra,
    };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test`
Expected: PASS (existing `computeTotals` tests + 3 new `computeFinalCalculation` tests)

- [ ] **Step 5: Commit**

```bash
git add resources/js/lib/calc.js resources/js/lib/__tests__/calc.test.js
git commit -m "feat: add computeFinalCalculation client-side mirror"
```

---

### Task 4: Rewrite `Books/FinalCalculation/Index.jsx`

**Files:**
- Modify: `resources/js/Pages/Books/FinalCalculation/Index.jsx` (full rewrite)

**Interfaces:**
- Consumes: Inertia props from Task 2 — `{ date, data, totals, saved, savedId, defaultOmrRate, denominations, count, history }`. `data` has the field names from `FinalCalculationService::compute()` (Task 1). `denominations` is `{ AED: number[], OMR: number[] }`. `count` is `null | { lines, extras, bundles, remarks }`.
- Consumes: `computeFinalCalculation` from `@/lib/calc` (Task 3).
- Produces: POSTs to `final-calc.store` with `{ calc_date, data: {...worksheet fields...}, remarks }`, and to `cash-count.store` with `{ count_date, lines, extras, bundles, remarks }` (the embedded cash-count widget's own save action).

This task has no automated test (no existing test coverage for page components in this codebase — verify via the browser, per Task 7).

- [ ] **Step 1: Replace the entire contents of `resources/js/Pages/Books/FinalCalculation/Index.jsx`**

```jsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { num } from '@/lib/format';
import { computeFinalCalculation } from '@/lib/calc';
import focusNextFieldOnEnter from '@/lib/focusNextFieldOnEnter';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const EDIT_MODE_STORAGE_KEY = 'finalCalc.editable';

const input = 'w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
const cell = input + ' text-right tabular-nums px-2 py-1';

// Fixed reconciliation ladder — mirrors the accountant's spreadsheet exactly.
const ROWS = [
    { key: 'opening_balance', label: 'Opening Balance' },
    { key: 'total_income', label: 'Total Income' },
    { key: 'customs_gov_fees', label: 'Total Customs/Gov. Fees Paid', negative: true },
    { key: 'credit_unpaid', label: 'Total Credit (Unpaid)', negative: true },
    { key: 'office_expenses', label: 'Office Expenses', negative: true },
    { key: 'total', label: 'TOTAL AMOUNT', total: 'total_amount', tone: 'green' },
    { key: 'borrowed_amount', label: 'Borrowed Amount' },
    { key: 'daily_credit_pending', label: 'Daily Credit (Pending)', negative: true },
    { key: 'total', label: 'TOTAL BALANCE AMOUNT', total: 'total_balance_amount', tone: 'blue' },
    { key: 'bank_ac_balance', label: 'All Bank A/C Balance', negative: true },
    { key: 'cdr_ac_balance', label: 'CDR A/C Balance', negative: true },
    { key: 'total', label: 'TOTAL CASH BALANCE IN HAND', total: 'total_cash_balance', tone: 'yellow' },
];

const TONE = {
    green: 'bg-emerald-100 text-emerald-900',
    blue: 'bg-sky-100 text-sky-900',
    yellow: 'bg-amber-100 text-amber-900',
};

export default function FinalCalculation({ date, data, totals, saved, savedId, defaultOmrRate, denominations, count, history }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);

    const [editable, setEditableState] = useState(() => {
        try {
            return localStorage.getItem(EDIT_MODE_STORAGE_KEY) === 'true';
        } catch {
            return false;
        }
    });
    const setEditable = (v) => {
        setEditableState(v);
        try {
            localStorage.setItem(EDIT_MODE_STORAGE_KEY, String(v));
        } catch {
            // localStorage unavailable — edit mode just won't persist across reloads.
        }
    };

    const { data: form, setData, post, processing } = useForm({
        calc_date: date,
        data: { omr_rate: defaultOmrRate, ...data },
        remarks: data.remarks ?? '',
    });

    const setField = (key, value) => setData('data', { ...form.data, [key]: value });

    // Mirror of FinalCalculationService::compute — on-screen totals must match the save.
    const t = useMemo(() => computeFinalCalculation(form.data), [form.data]);
    const extra = t.cash_extra;

    const changeDate = (d) => router.get(route('final-calc.index'), { date: d }, { preserveState: false });
    const recompute = () => router.get(route('final-calc.index'), { date: form.calc_date, fresh: 1 }, { preserveState: false });
    const save = (e) => {
        e.preventDefault();
        setData('remarks', form.data.remarks);
        post(route('final-calc.store'), { preserveScroll: true });
    };
    const deleteSnapshot = (h) => {
        if (confirm(`Delete the Final Calculation snapshot for ${h.date}?`)) {
            router.delete(route('final-calc.destroy', h.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header="Final Calculation">
            <Head title="Final Calculation" />

            <div onKeyDown={focusNextFieldOnEnter}>
                <div className="mb-4 flex flex-wrap items-end gap-3">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Date</span>
                        <input type="date" className={input + ' w-44'} value={form.calc_date}
                            onChange={(e) => { setData('calc_date', e.target.value); changeDate(e.target.value); }} />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">OMR → AED rate</span>
                        <input type="number" step="0.0001" className={input + ' w-32 text-right'} value={form.data.omr_rate ?? defaultOmrRate}
                            onChange={(e) => setField('omr_rate', e.target.value)} />
                    </label>
                    <div className="flex-1" />
                    <EditModeToggle editable={editable} setEditable={setEditable} />
                    <button type="button" onClick={recompute} disabled={!editable} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">
                        Recompute from live
                    </button>
                    {saved && (
                        <a href={route('final-calc.pdf', savedId)} target="_blank" className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50">
                            Print / PDF
                        </a>
                    )}
                    <button onClick={save} disabled={processing || !editable} className="rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                        {saved ? 'Update' : 'Save'}
                    </button>
                </div>

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <Card className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-[#f6d9c3] text-left text-xs font-bold uppercase text-navy-900">
                                    <th className="px-3 py-2">Details</th>
                                    <th className="px-3 py-2 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {ROWS.map((row, i) => (
                                    <DetailRow key={row.total ?? row.key} row={row} idx={i} t={t} form={form} editable={editable} setField={setField} />
                                ))}
                            </tbody>
                        </table>
                    </Card>

                    <CashCountWidget date={form.calc_date} denominations={denominations} count={count} />
                </div>

                {/* Reconciliation */}
                <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <Stat label="Total Cash Balance In Hand" value={num(t.total_cash_balance)} accent="text-emerald-700" big />
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs uppercase text-slate-500">Cash Counted</p>
                        <p className="mt-2 text-2xl font-bold text-navy-900">{num(t.cash_counted)} <Tag>AED equiv.</Tag></p>
                    </div>
                    <div className={'rounded-xl border p-4 shadow-sm ' + (extra === 0 ? 'border-emerald-200 bg-emerald-50' : extra > 0 ? 'border-amber-200 bg-amber-50' : 'border-red-200 bg-red-50')}>
                        <p className="text-xs uppercase text-slate-500">Cash Extra</p>
                        <p className={'mt-1 text-2xl font-bold ' + (extra === 0 ? 'text-emerald-700' : extra > 0 ? 'text-amber-600' : 'text-accent-red')}>
                            {extra === 0 ? 'Balanced ✓' : `${extra > 0 ? 'Over ' : 'Short '}${num(Math.abs(extra))}`}
                        </p>
                    </div>
                </div>

                <Card className="mt-4">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Remarks</span>
                        <textarea rows="2" className={input} value={form.data.remarks ?? ''}
                            onChange={(e) => setField('remarks', e.target.value)} />
                    </label>
                </Card>
            </div>

            <Card title="Recent Snapshots" className="mt-4">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4 text-right">Total Cash Balance</th>
                                <th className="py-2 pr-4 text-right">Cash Extra</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.length === 0 && <tr><td colSpan="4" className="py-6 text-center text-slate-400">No snapshots saved yet.</td></tr>}
                            {history.map((h) => (
                                <tr key={h.id} className="border-b last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-4">{h.date}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{num(h.total_cash_balance)}</td>
                                    <td className={'py-2 pr-4 text-right font-semibold tabular-nums ' + (h.cash_extra === 0 ? 'text-emerald-700' : h.cash_extra > 0 ? 'text-amber-600' : 'text-accent-red')}>
                                        {h.cash_extra > 0 ? '+' : ''}{num(h.cash_extra)}
                                    </td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        <a href={route('final-calc.pdf', h.id)} target="_blank" className="text-primary-600 hover:underline">PDF</a>
                                        <button onClick={() => changeDate(h.date)} className="ml-3 text-navy-600 hover:underline">Edit</button>
                                        {canWrite && <button onClick={() => deleteSnapshot(h)} className="ml-3 text-accent-red hover:underline">Delete</button>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}

function DetailRow({ row, t, form, editable, setField }) {
    if (row.total) {
        return (
            <tr className={'font-bold ' + TONE[row.tone]}>
                <td className="px-3 py-2">{row.label}</td>
                <td className="px-3 py-2 text-right tabular-nums">{num(t[row.total])}</td>
            </tr>
        );
    }

    return (
        <tr className="border-b border-slate-100">
            <td className="px-3 py-2 text-navy-800">{row.label}</td>
            <td className="px-2 py-1">
                <div className="flex items-center justify-end gap-1">
                    {row.negative && <span className="text-xs text-slate-400">−</span>}
                    <input type="number" step="0.01"
                        className={cell + ' disabled:border-slate-200 disabled:bg-slate-50 disabled:text-navy-800'}
                        value={form.data[row.key] ?? ''} disabled={!editable}
                        onChange={(e) => setField(row.key, e.target.value)} />
                </div>
            </td>
        </tr>
    );
}

// The AED/OMR denomination + bundle count, relocated from the Daily Cash
// Count page. Saves independently via its own "Save Count" action — see
// docs/superpowers/specs/2026-08-06-final-calculation-redesign-design.md.
function CashCountWidget({ date, denominations, count }) {
    const { data, setData, post, processing } = useForm({
        count_date: date,
        lines: count?.lines ?? { AED: {}, OMR: {} },
        // extras/remarks aren't edited here (they live on the Daily Cash
        // Count page) but must round-trip unchanged on save.
        extras: count?.extras ?? { AED: [], OMR: [] },
        bundles: count?.bundles ?? { AED: [], OMR: [] },
        remarks: count?.remarks ?? '',
    });

    const setQty = (cur, denom, qty) => setData('lines', { ...data.lines, [cur]: { ...data.lines[cur], [denom]: qty } });

    const updateBundle = (cur, idx, key, value) => {
        const bundles = (data.bundles[cur] || []).map((b, i) => (i === idx ? { ...b, [key]: value } : b));
        setData('bundles', { ...data.bundles, [cur]: bundles });
    };
    const addBundle = (cur) => {
        const bundles = data.bundles[cur] || [];
        setData('bundles', { ...data.bundles, [cur]: [...bundles, { label: `Bundle-${bundles.length + 1}`, amount: '' }] });
    };
    const removeBundle = (cur, idx) => setData('bundles', { ...data.bundles, [cur]: (data.bundles[cur] || []).filter((_, i) => i !== idx) });

    const denomTotal = (cur) => {
        let sum = 0;
        for (const d of denominations[cur]) sum += d * (parseFloat(data.lines[cur]?.[d]) || 0);
        for (const b of data.bundles[cur] || []) sum += parseFloat(b.amount) || 0;
        return cur === 'OMR' ? Math.round(sum * 1000) / 1000 : Math.round(sum * 100) / 100;
    };

    const save = () => post(route('cash-count.store'), { preserveScroll: true });

    return (
        <div className="space-y-6">
            {Object.keys(denominations).map((cur) => (
                <Card key={cur} title={`${cur} Cash Count`} action={<span className="text-sm font-bold text-primary-700">{num(denomTotal(cur))}</span>}>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-1.5 pr-3">Denomination</th>
                                <th className="py-1.5 pr-3 text-center">Qty</th>
                                <th className="py-1.5 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {denominations[cur].map((d) => {
                                const qty = parseFloat(data.lines[cur]?.[d]) || 0;
                                return (
                                    <tr key={d} className="border-b last:border-0">
                                        <td className="py-1 pr-3 font-medium text-navy-800">{num(d)}</td>
                                        <td className="py-1 pr-3">
                                            <input type="number" min="0" step="1" className={input + ' text-center'} value={data.lines[cur]?.[d] ?? ''} onChange={(e) => setQty(cur, d, e.target.value)} />
                                        </td>
                                        <td className="py-1 text-right tabular-nums text-slate-600">{qty ? num(d * qty) : '—'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>

                    <div className="mt-4 border-t pt-3">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-xs font-semibold uppercase text-slate-600">Bundles</span>
                            <button type="button" onClick={() => addBundle(cur)} className="text-xs font-semibold text-primary-600 hover:underline">+ Add Bundle</button>
                        </div>
                        {(data.bundles[cur] || []).length > 0 && (
                            <table className="w-full text-sm">
                                <tbody>
                                    {(data.bundles[cur] || []).map((b, idx) => (
                                        <tr key={idx} className="border-b last:border-0">
                                            <td className="py-1 pr-3">
                                                <input type="text" className={input} value={b.label} onChange={(e) => updateBundle(cur, idx, 'label', e.target.value)} />
                                            </td>
                                            <td className="py-1 pr-3 w-36">
                                                <input type="number" step="0.01" className={input + ' text-right'} placeholder="0.00" value={b.amount} onChange={(e) => updateBundle(cur, idx, 'amount', e.target.value)} />
                                            </td>
                                            <td className="py-1 pl-1 text-center w-6">
                                                <button type="button" onClick={() => removeBundle(cur, idx)} className="text-accent-red hover:text-accent-red-dark">✕</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </Card>
            ))}
            <button type="button" onClick={save} disabled={processing} className="w-full rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                Save Cash Count
            </button>
        </div>
    );
}

function EditModeToggle({ editable, setEditable }) {
    const options = [
        { value: true, label: 'Enable editing' },
        { value: false, label: 'Disable editing' },
    ];
    return (
        <fieldset className="flex rounded-lg border border-slate-300 bg-white p-0.5 text-sm">
            <legend className="sr-only">Edit mode</legend>
            {options.map((opt) => (
                <label key={opt.label} className={'cursor-pointer rounded-md px-3 py-1.5 font-semibold transition ' +
                    (editable === opt.value ? 'bg-primary-600 text-white shadow-sm' : 'text-navy-700 hover:bg-slate-50')}>
                    <input type="radio" name="finalCalcEditMode" className="sr-only" checked={editable === opt.value}
                        onChange={() => setEditable(opt.value)} />
                    {opt.label}
                </label>
            ))}
        </fieldset>
    );
}

function Tag({ children }) {
    return <span className="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{children}</span>;
}

function Stat({ label, value, accent, big }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className={`mt-1 font-bold ${big ? 'text-3xl' : 'text-2xl'} ${accent}`}>{value}</p>
        </div>
    );
}
```

- [ ] **Step 2: Visually verify in the browser**

Start the dev server (`php artisan serve` if not already running, `npm run build` or `npm run dev`), then in the Browser pane:
1. Navigate to `/books/final-calculation`.
2. Confirm the left table shows the 12 rows in spreadsheet order with green/blue/yellow total rows, and the right side shows AED and OMR Cash Count cards with denomination inputs and an "+ Add Bundle" control.
3. Toggle "Enable editing", change a value (e.g. Opening Balance), confirm the green/blue/yellow totals update live without a page reload.
4. Enter a denomination quantity in the AED card, click "Save Cash Count", confirm the page reloads with the "Cash Counted" reconciliation card reflecting the new total.
5. Click "Save"/"Update" on the worksheet, confirm it redirects back with a success banner and "Recent Snapshots" shows the new row.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Books/FinalCalculation/Index.jsx
git commit -m "feat: rebuild Final Calculation page around the fixed spreadsheet layout"
```

---

### Task 5: Trim `CashCount/Index.jsx`

**Files:**
- Modify: `resources/js/Pages/CashCount/Index.jsx` (remove denomination tables, bundle UI, and the reconciliation banner; keep date picker, IN/OUT slips table, remarks, save, and history)

**Interfaces:**
- Consumes: existing `{ date, denominations, count, history }` props (`CashCountController` is unchanged — `expectedAed`/`omrRate` props are simply no longer destructured/used by this page, which is harmless).
- Produces: still POSTs `{ count_date, lines, bundles, extras, remarks }` to `cash-count.store` — `lines`/`bundles` are carried through unedited from `count` so a save here doesn't blank out what was entered on the Final Calculation page.

No automated test — verify via the browser in Task 7.

- [ ] **Step 1: Replace the entire contents of `resources/js/Pages/CashCount/Index.jsx`**

```jsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { money, num } from '@/lib/format';
import focusNextFieldOnEnter from '@/lib/focusNextFieldOnEnter';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function CashCount({ date, denominations, count, history }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);

    const { data, setData, post, processing } = useForm({
        count_date: date,
        // The AED/OMR denomination + bundle counts are edited on the Final
        // Calculation page now — this form still carries them so saving here
        // doesn't wipe out what was entered there.
        lines: count?.lines ?? { AED: {}, OMR: {} },
        bundles: count?.bundles ?? { AED: [], OMR: [] },
        extras: count?.extras ?? { AED: [], OMR: [] },
        remarks: count?.remarks ?? '',
    });

    const updateExtra = (cur, idx, key, value) => {
        const extras = [...(data.extras[cur] || [])];
        while (extras.length <= idx) extras.push({ label: '', amount: '' });
        extras[idx][key] = value;
        setData('extras', { ...data.extras, [cur]: extras });
    };

    const addExtra = (cur) => setData('extras', { ...data.extras, [cur]: [...(data.extras[cur] || []), { label: '', amount: '' }] });

    const changeDate = (d) => router.get(route('cash-count.index'), { date: d }, { preserveState: false });
    const save = (e) => { e.preventDefault(); post(route('cash-count.store'), { preserveScroll: true }); };
    const deleteCount = (h) => {
        if (confirm(`Delete the cash count for ${h.date}?`)) {
            router.delete(route('cash-count.destroy', h.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header="Daily Cash Count">
            <Head title="Daily Cash Count" />

            <div onKeyDown={focusNextFieldOnEnter}>
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Count Date</span>
                    <input type="date" className={input + ' w-44'} value={data.count_date} onChange={(e) => { setData('count_date', e.target.value); changeDate(e.target.value); }} />
                </label>
                <button onClick={save} disabled={processing} className="rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                    Save Count
                </button>
                <p className="text-xs text-slate-500">
                    AED/OMR denomination counting now happens on the{' '}
                    <a href={route('final-calc.index')} className="text-primary-600 hover:underline">Final Calculation</a> page.
                </p>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {Object.keys(denominations).map((cur) => (
                    <Card key={cur} title={`${cur} Bundles / Slips`}>
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs border-collapse">
                                <thead>
                                    <tr className="bg-slate-100">
                                        <th colSpan="2" className="border border-slate-400 py-2 text-center font-bold text-navy-800">IN</th>
                                        <th colSpan="2" className="border border-slate-400 py-2 text-center font-bold text-navy-800">OUT</th>
                                    </tr>
                                    <tr className="bg-slate-50">
                                        <th className="border border-slate-400 py-1.5 px-2 text-left text-slate-600">Details</th>
                                        <th className="border border-slate-400 py-1.5 px-2 text-right text-slate-600">Amount</th>
                                        <th className="border border-slate-400 py-1.5 px-2 text-left text-slate-600">Details</th>
                                        <th className="border border-slate-400 py-1.5 px-2 text-right text-slate-600">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(() => {
                                        const extras = data.extras[cur] || [];
                                        const maxRows = Math.max(Math.ceil(extras.length / 2), 5);

                                        return Array.from({ length: maxRows }).map((_, row) => {
                                            const inIdx = row * 2;
                                            const outIdx = row * 2 + 1;
                                            const inItem = extras[inIdx] || { label: '', amount: '' };
                                            const outItem = extras[outIdx] || { label: '', amount: '' };

                                            return (
                                                <tr key={row}>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input type="text" className={input + ' text-xs'} placeholder="Details" value={inItem.label} onChange={(e) => updateExtra(cur, inIdx, 'label', e.target.value)} />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input type="number" step="0.01" className={input + ' text-xs text-right'} placeholder="0.00" value={inItem.amount} onChange={(e) => updateExtra(cur, inIdx, 'amount', e.target.value)} />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input type="text" className={input + ' text-xs'} placeholder="Details" value={outItem.label} onChange={(e) => updateExtra(cur, outIdx, 'label', e.target.value)} />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input type="number" step="0.01" className={input + ' text-xs text-right'} placeholder="0.00" value={outItem.amount} onChange={(e) => updateExtra(cur, outIdx, 'amount', e.target.value)} />
                                                    </td>
                                                </tr>
                                            );
                                        });
                                    })()}
                                    <tr className="border-t-2 border-navy-800 bg-slate-100 font-bold">
                                        <td className="border border-slate-400 py-2 px-2 text-right">Total</td>
                                        <td className="border border-slate-400 py-2 px-2 text-right tabular-nums">
                                            {(() => {
                                                const inTotal = (data.extras[cur] || [])
                                                    .filter((_, i) => i % 2 === 0)
                                                    .reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
                                                return num(inTotal);
                                            })()}
                                        </td>
                                        <td className="border border-slate-400 py-2 px-2 text-right">Total</td>
                                        <td className="border border-slate-400 py-2 px-2 text-right tabular-nums">
                                            {(() => {
                                                const outTotal = (data.extras[cur] || [])
                                                    .filter((_, i) => i % 2 === 1)
                                                    .reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
                                                return num(outTotal);
                                            })()}
                                        </td>
                                    </tr>
                                    <tr className="font-bold text-navy-800">
                                        <td colSpan="2" className="border border-slate-400 py-2 px-2">Balance Amount</td>
                                        <td colSpan="2" className="border border-slate-400 py-2 px-2 text-right tabular-nums">
                                            {(() => {
                                                const inTotal = (data.extras[cur] || [])
                                                    .filter((_, i) => i % 2 === 0)
                                                    .reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
                                                const outTotal = (data.extras[cur] || [])
                                                    .filter((_, i) => i % 2 === 1)
                                                    .reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
                                                return num(inTotal - outTotal);
                                            })()}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-3 flex justify-end">
                            <button type="button" onClick={() => addExtra(cur)} className="text-xs font-semibold text-primary-600 hover:underline">+ Add Row</button>
                        </div>
                    </Card>
                ))}
            </div>

            <Card className="mt-4">
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Remarks</span>
                    <textarea rows="2" className={input} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                </label>
            </Card>
            </div>

            {/* History */}
            <Card title="Recent Counts" className="mt-4">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4 text-right">AED</th>
                                <th className="py-2 pr-4 text-right">OMR</th>
                                <th className="py-2 pr-4 text-right">Difference</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.length === 0 && <tr><td colSpan="5" className="py-6 text-center text-slate-400">No counts saved yet.</td></tr>}
                            {history.map((h) => (
                                <tr key={h.id} className="border-b last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-4">{h.date}</td>
                                    <td className="py-2 pr-4 text-right">{money(h.total_aed, 'AED')}</td>
                                    <td className="py-2 pr-4 text-right">{money(h.total_omr, 'OMR')}</td>
                                    <td className={'py-2 pr-4 text-right font-semibold ' + (h.variance === 0 ? 'text-emerald-700' : h.variance > 0 ? 'text-amber-600' : 'text-accent-red')}>
                                        {h.variance === 0 ? 'Balanced' : `${h.variance > 0 ? '+' : ''}${num(h.variance)}`}
                                    </td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        <a href={route('cash-count.pdf', h.id)} target="_blank" className="text-primary-600 hover:underline">PDF</a>
                                        <button onClick={() => changeDate(h.date)} className="ml-3 text-navy-600 hover:underline">Edit</button>
                                        {canWrite && <button onClick={() => deleteCount(h)} className="ml-3 text-accent-red hover:underline">Delete</button>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Visually verify in the browser**

1. Navigate to `/cash-count`.
2. Confirm the denomination tables and the old reconciliation banner are gone; only the date picker, "Save Count" button, the pointer note to Final Calculation, the two "Bundles / Slips" (IN/OUT) cards, Remarks, and "Recent Counts" remain.
3. Enter an IN/OUT slip row, click "Save Count", confirm it saves without errors.
4. Go back to `/books/final-calculation` for the same date and confirm the AED/OMR denomination counts you entered there earlier (Task 4 Step 2) are still intact — proving this page's save didn't clobber them.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/CashCount/Index.jsx
git commit -m "refactor: move denomination cash-count UI off the Daily Cash Count page"
```

---

### Task 6: Rewrite the Final Calculation PDF view

**Files:**
- Modify: `resources/views/final-calculation/pdf.blade.php` (full rewrite)

**Interfaces:**
- Consumes: `$calc` (a `FinalCalculation` model instance) and `$totals` (the array from `FinalCalculationService::compute()`, Task 1) — both passed by `FinalCalculationController::pdf()` (Task 2, unchanged wiring).

- [ ] **Step 1: Replace the entire contents of `resources/views/final-calculation/pdf.blade.php`**

```blade
<!DOCTYPE html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #10222f; }
    .head { border-bottom: 3px solid #1b9a9b; padding-bottom: 8px; margin-bottom: 14px; }
    .company { font-size: 18px; font-weight: bold; color: #1e3a5f; }
    .sub { font-size: 13px; color: #158a8b; }
    table.fc { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.fc th { background: #f6d9c3; color: #1e3a5f; text-align: right; padding: 6px; font-size: 10px; text-transform: uppercase; }
    table.fc th.l { text-align: left; }
    table.fc td { padding: 5px 8px; border-bottom: 1px solid #e8edf2; }
    .r { text-align: right; }
    tr.green td { background: #d1fae5; font-weight: bold; color: #065f46; }
    tr.blue td { background: #dbeafe; font-weight: bold; color: #1e40af; }
    tr.yellow td { background: #fef3c7; font-weight: bold; color: #92400e; }
    .boxes { width: 100%; margin-top: 6px; }
    .boxes td { width: 33.3%; padding: 8px; vertical-align: top; }
    .box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; }
    .box .lbl { font-size: 9px; text-transform: uppercase; color: #64748b; }
    .box .val { font-size: 18px; font-weight: bold; margin-top: 3px; }
    .liquid { border-color: #6ee7b7; background: #ecfdf5; }
    .liquid .val { color: #047857; }
    .short { border-color: #fca5a5; background: #fef2f2; }
    .short .val { color: #b91c1c; }
    .over { border-color: #fcd34d; background: #fffbeb; }
    .over .val { color: #b45309; }
</style></head>
<body>
    @php
        $fmt = fn ($v) => number_format((float) $v, 2);
        $extra = (float) $totals['cash_extra'];
        $rows = [
            ['Opening Balance', $totals['opening_balance']],
            ['Total Income', $totals['total_income']],
            ['Total Customs/Gov. Fees Paid', -$totals['customs_gov_fees']],
            ['Total Credit (Unpaid)', -$totals['credit_unpaid']],
            ['Office Expenses', -$totals['office_expenses']],
            ['TOTAL AMOUNT', $totals['total_amount'], 'green'],
            ['Borrowed Amount', $totals['borrowed_amount']],
            ['Daily Credit (Pending)', -$totals['daily_credit_pending']],
            ['TOTAL BALANCE AMOUNT', $totals['total_balance_amount'], 'blue'],
            ['All Bank A/C Balance', -$totals['bank_ac_balance']],
            ['CDR A/C Balance', -$totals['cdr_ac_balance']],
            ['TOTAL CASH BALANCE IN HAND', $totals['total_cash_balance'], 'yellow'],
        ];
    @endphp

    <div class="head">
        <div class="company">CMV Shipping</div>
        <div class="sub">Final Calculation — {{ $calc->calc_date->format('d-m-Y') }}</div>
    </div>

    <table class="fc">
        <thead>
            <tr>
                <th class="l">Details</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr class="{{ $row[2] ?? '' }}">
                    <td>{{ $row[0] }}</td>
                    <td class="r">{{ $fmt($row[1]) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="boxes"><tr>
        <td><div class="box liquid"><div class="lbl">Total Cash Balance In Hand</div><div class="val">{{ $fmt($totals['total_cash_balance']) }}</div></div></td>
        <td><div class="box"><div class="lbl">Cash Counted (AED equiv.)</div><div class="val">{{ $fmt($totals['cash_counted']) }}</div></div></td>
        <td><div class="box {{ $extra == 0 ? '' : ($extra > 0 ? 'over' : 'short') }}"><div class="lbl">Cash Extra</div>
            <div class="val">{{ $extra == 0 ? 'Balanced' : ($extra > 0 ? 'Over ' : 'Short ').number_format(abs($extra), 2) }}</div></div></td>
    </tr></table>

    @if($calc->remarks)
        <div style="margin-top:10px;"><strong>Remarks:</strong> {{ $calc->remarks }}</div>
    @endif
</body>
</html>
```

- [ ] **Step 2: Run the full `FinalCalculationTest` suite**

Run: `php artisan test --filter=FinalCalculationTest`
Expected: PASS (8 tests) — this is the test from Task 2 that was blocked on the old Blade view; it must fully pass now.

- [ ] **Step 3: Visually spot-check one PDF**

In the browser, on `/books/final-calculation`, save a snapshot and click "Print / PDF". Confirm it downloads and opens showing the 12-row table with green/blue/yellow rows, the three reconciliation boxes, and (if set) the remarks line.

- [ ] **Step 4: Commit**

```bash
git add resources/views/final-calculation/pdf.blade.php
git commit -m "feat: rewrite Final Calculation PDF for the fixed breakdown layout"
```

---

### Task 7: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the full PHP test suite**

Run: `php artisan test`
Expected: PASS, all tests (the full suite, not just the Final Calculation / Cash Count files — confirms nothing else regressed, e.g. `BankService`/`BankAccounts` tests still pass unchanged since `rawBankBalances()` is a new, separate method).

- [ ] **Step 2: Run the full JS test suite**

Run: `npm run test`
Expected: PASS, all tests.

- [ ] **Step 3: Build the frontend**

Run: `npm run build`
Expected: builds cleanly with no errors.

- [ ] **Step 4: Browser walkthrough**

1. `/books/final-calculation` — load with no snapshot for today, confirm live defaults populate all 12 rows and the AED/OMR cash count widgets.
2. Toggle editing off — confirm all inputs (worksheet rows) become disabled/read-only; the cash count widget stays editable regardless (it's not gated by the toggle).
3. Save a snapshot, reload the page, confirm `saved` state shows "Update"/"Print / PDF" and the frozen figures reopen correctly.
4. `/cash-count` — confirm IN/OUT slips still work and the pointer link to Final Calculation is visible.
5. Check `/dashboard` and `/bank-accounts` still show correct figures (proving `BankService::balances()` truly wasn't touched).

- [ ] **Step 5: Final commit (if anything was left uncommitted)**

```bash
git status
git add -A
git commit -m "chore: final regression pass for the Final Calculation redesign" --allow-empty
```

(Skip this step entirely if `git status` shows a clean tree — every prior task already committed its own changes.)
