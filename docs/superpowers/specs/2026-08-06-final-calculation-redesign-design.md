# Final Calculation Page Redesign — Design

**Date:** 2026-08-06
**Branch:** phase1-build
**Status:** Approved for planning
**Supersedes:** the row-grid worksheet model from `2026-07-29-final-calculation-page-design.md`
(that spec's page shipped and is live; this spec replaces its data model and layout).

## Purpose

Rebuild the "Final Calculation" page's main worksheet to match the accountant's actual
paper/Excel layout — a fixed reconciliation ladder (Opening Balance → Total Income →
… → Total Cash Balance In Hand) instead of the current free-form row grid — and relocate
the AED/OMR cash-counting UI onto this page from the standalone Daily Cash Count page.

Source: a screenshot of the accountant's spreadsheet (DETAILS/AMOUNT table +
AED CASH COUNT + OMR CASH COUNT tables), reproduced exactly below.

## Confirmed layout (from the screenshot)

```
DETAILS                          AMOUNT
Opening Balance                  64,061
Total Income                     15,793
Total Customs/Gov. Fees Paid    -11,688
Total Credit (Unpaid)            -8,850
Office Expenses                  -2,434
TOTAL AMOUNT (green)             56,882

Borrowed Amount                  89,700
Daily Credit (Pending)          -58,069
TOTAL BALANCE AMOUNT (blue)      88,513

All Bank A/C Balance            -56,684
CDR A/C Balance                 -19,927
TOTAL CASH BALANCE IN HAND       11,902  (yellow)
```

Verified: `64061 + 15793 - 11688 - 8850 - 2434 = 56882`;
`56882 + 89700 - 58069 = 88513`; `88513 - 56684 - 19927 = 11902`.

Alongside it: an **AED Cash Count** table (denominations 1000/500/200/100/50/20/10/5,
Qty + Amount columns, a Bundle-1 row, "Add row", and a Total Amount) and an
**OMR Cash Count** table (same shape, denominations 50/20/10/5/1/0.5/0.1).

## Decisions (from clarifying Q&A)

- **Real separate figures**, not a relabeling of the existing `dwsBalance()` — each row
  is its own backend computation.
- **Cumulative to date** (≤ selected `calc_date`), same convention `dwsBalance()` already
  used, for: Total Income, Total Customs/Gov Fees Paid, Total Credit (Unpaid), Office
  Expenses. Opening Balance is a single setting. Borrowed Amount, Daily Credit (Pending),
  bank balances stay **live current values** (unchanged from today's non-date-scoped
  behavior for those figures).
- **Total Income** = Σ `Transaction.profit` (the shipment markup/earnings field) — not
  `net_profit`. Per-shipment expenses (`TransactionExpense`) and reference commissions
  are **dropped from this calculation entirely** — not tracked in this breakdown.
- **Customs/gov fees are subtracted exactly once**, via the "Total Customs/Gov Fees Paid"
  row. The "All Bank A/C Balance" / "CDR A/C Balance" rows use each bank's raw balance
  (opening balance + manual `BankEntry` in/out only) — **not** `BankService::balances()`,
  which already nets out customs/gov/office fees for its own (unrelated) callers. This is
  a new, page-local computation; `BankService::balances()` is untouched.
- **Cash count tables move**, denominations + bundles only. The Daily Cash Count page's
  IN/OUT slips table and Recent Counts history **stay behind** (they're already excluded
  from `CashCount::totalFor()`, so nothing about the counted total depends on them).
- **Cash count keeps its own save action** (`cash-count.store`), now submitted from a
  widget embedded in the Final Calculation page, rather than merging into one combined
  save with the worksheet.

## Architecture

### `FinalCalculationService` — rewritten `compute()` / `defaults()`

`compute(array $data): array` becomes a pure function over the fixed field set instead of
a `rows` array:

```php
compute([
    'opening_balance' => ..., 'total_income' => ..., 'customs_gov_fees' => ...,
    'credit_unpaid' => ..., 'office_expenses' => ...,
    'borrowed_amount' => ..., 'daily_credit_pending' => ...,
    'bank_ac_balance' => ..., 'cdr_ac_balance' => ...,
    'aed_counted' => ..., 'omr_counted' => ..., 'omr_rate' => ...,
])
// => ['total_amount', 'total_balance_amount', 'total_cash_balance', 'cash_extra', ...same inputs echoed]
```

Formulas:
- `total_amount = opening_balance + total_income - customs_gov_fees - credit_unpaid - office_expenses`
- `total_balance_amount = total_amount + borrowed_amount - daily_credit_pending`
- `total_cash_balance = total_balance_amount - bank_ac_balance - cdr_ac_balance`
- `cash_extra = (aed_counted + omr_counted * omr_rate) - total_cash_balance`

`defaults(string $date): array` builds the live-computed values:

| Field | Source |
|---|---|
| `opening_balance` | `Setting::get('cash_opening_balance', 0)` |
| `total_income` | `Transaction::whereDate('transaction_date','<=',$date)->sum('profit')` |
| `customs_gov_fees` | `Transaction::whereDate('transaction_date','<=',$date)->sum('customs_fees')` + same for `gov_fees` |
| `credit_unpaid` | existing `BalanceService::creditOutstandingAsOf($date)` (reused as-is) |
| `office_expenses` | `OfficeExpense::whereDate('expense_date','<=',$date)->sum('amount')` |
| `borrowed_amount` | existing `ledgerOutstanding(LedgerEntry::TYPE_BORROWED)` (unchanged, live) |
| `daily_credit_pending` | existing `ledgerOutstanding(LedgerEntry::TYPE_CREDIT)` (unchanged, live) |
| `bank_ac_balance` / `cdr_ac_balance` | new `rawBankBalances()` helper: Σ(`Bank.opening_balance` + in `BankEntry`s − out `BankEntry`s), split by `BankService::customsBank()` |
| `aed_counted` / `omr_counted` | that date's `CashCount.total_aed` / `total_omr`, 0 if none saved yet |
| `omr_rate` | existing `omrRate()` (unchanged) |

`carriedManualRows()`/manual-row carry-forward and the `withDwsCashDefault()` overlay are
removed — there's no row list left to carry, and cash counts are read directly into
`aed_counted`/`omr_counted` on every load (same "always reflect the latest count" property
the old overlay provided, just simpler since it's now two scalar fields, not a row patch).

### `FinalCalculationController`

- `index()` — unchanged shape (`?date=`, snapshot-or-defaults, `fresh=1` recompute). Also
  passes `denominations` (`CashCount::DENOMINATIONS`) and that date's `count` (lines/bundles
  only — no `extras`) so the page can render and edit the AED/OMR widgets itself, mirroring
  what `CashCountController::index()` sends today.
- `store()` — validates the fixed field set instead of `data.rows`.
- `pdf()` — unchanged wiring; the Blade view is rewritten to the new layout.

### `FinalCalculation` model / table

No migration. `data` (JSON) holds the new fixed-field shape. Existing decimal columns are
reused with updated meaning for the history list and PDF: `total_amount` → the new "Total
Amount" subtotal, `liquid_cash` → "Total Cash Balance In Hand", `cash_counted`/`cash_extra`
unchanged in meaning. `total_ac_balance`/`total_debt_exp` stop being populated (default 0
for new rows; harmless for old snapshots, which remain readable via `data` if ever needed).

### `CashCountController`

Unchanged. Its `store()` already ignores `extras` when computing `total_aed`/`total_omr`,
so nothing here needs to change for the relocation.

### Frontend

**`Books/FinalCalculation/Index.jsx`** — two-column layout:
- Left: the fixed 12-row Details/Amount table, colored to match the sheet (green Total
  Amount, blue Total Balance Amount, yellow Total Cash Balance In Hand; deduction rows
  shown as negative numbers). The existing "Enable editing" toggle now guards manual
  overrides of these 12 values (replacing the old per-cell/add-row worksheet editing).
- Right: AED Cash Count and OMR Cash Count widgets — the denomination table + bundle
  rows moved from `CashCount/Index.jsx`, with their own "Save Count" button posting to
  `cash-count.store`. Always editable (matches today's Cash Count page behavior — not
  gated by the worksheet's edit toggle).
- Reconciliation cards (Total Cash Balance In Hand / Counted / Cash Extra) replace the
  old "Total Liquid Cash" / "Cash Counted" / "Cash Extra" trio, same visual treatment.

**`CashCount/Index.jsx`** — denomination tables, the AED/OMR total cards, and their
"Counted/Expected/Difference" reconciliation banner are removed. Date picker, IN/OUT
slips table, remarks, save button, and Recent Counts history stay, unchanged.

No route or nav changes — both pages keep their existing URLs and sidebar entries.

## Testing

- Rewrite `FinalCalculationComputeTest` against the new formula, using the screenshot's
  own numbers as the locked example (`64061/15793/11688/8850/2434 → 56882`,
  `+89700/-58069 → 88513`, `-56684/-19927 → 11902`).
- Rewrite `FinalCalculationTest` (feature) for the new field set: save/reopen/upsert/
  role-guard/PDF, plus a case asserting `defaults()` pulls `total_income` from `profit`
  (not `net_profit`) and excludes per-shipment expenses/commissions.
- New unit coverage for the raw bank/CDR split (opening balance + entries only, no fee
  netting) — distinct from existing `BankService::balances()` tests, which must keep
  passing unchanged.
- `CashCountTest` — no expected changes; add a quick assertion that Final Calculation's
  `index()` response includes `denominations` and the date's `count` (lines/bundles).

## Out of scope

- Any change to `BankService::balances()` or the Bank Accounts / dashboard / statement
  pages that consume it.
- Moving the IN/OUT slips table or Recent Counts history off the Daily Cash Count page.
- Merging the cash count save into the Final Calculation worksheet save (stays two
  separate actions).
- Historical backfill/migration of old `FinalCalculation.data` snapshots to the new shape
  — old snapshots remain viewable via their raw `data`, but aren't rewritten.
