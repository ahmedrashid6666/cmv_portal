# Final Calculation Page — Design

**Date:** 2026-07-29
**Branch:** phase1-build
**Status:** Approved for planning

## Purpose

Reproduce the accountant's Excel "FINAL CALCULATION" reconciliation worksheet as a
single in-app page. It consolidates the day's cash position, borrowed cash, daily
credit, all bank balances, and monthly bank expenses into one page, and computes the
**Total Liquid Cash in CMV** and the **Cash Extra** (over/short) figure.

Source of truth for the formulas: `ACCOUNT WORKBOOK.xlsm`, first sheet, rows 174–195.

## Confirmed formulas (from the workbook)

Columns: **AMOUNT | A/C BALANCE | DEBT/EXP | CASH**

| Row | Column | Cell | Formula / value |
|---|---|---|---|
| Daily Work Sheet Bal | AMOUNT | F176 | day's cash position (`cashBalance`) |
| Borrowed Cash | AMOUNT | F177 | borrowed total |
| Daily Credit Total | DEBT/EXP | J178 | daily-credit outstanding |
| Daily Credit (cash) | CASH | L178 | manual |
| RAK / ADCB / FAB / DIB | A/C BALANCE | H180–H183 | bank balances |
| CDR Account (OMR) | A/C BALANCE | H184 | customs bank balance |
| RAK / ADCB A/C Exp this month | DEBT/EXP | J187–J188 | monthly bank fees |
| RAK exp (cash) | CASH | L187 | OMR × rate (`250 × 9.5238`) |
| Suhail Salary Deposit | A/C BALANCE | H189 | manual |

**Totals & reconciliation:**

- `TOTAL AMOUNT`      = Daily Work Sheet Bal + Borrowed Cash                    → `F191 = SUM(F176:G177)`
- `TOTAL A/C BALANCE` = Σ(bank balances + CDR + salary deposit)                → `H191 = SUM(H175:I190)`
- `TOTAL DEBT/EXP`    = Daily Credit total + Σ(monthly bank expenses)          → `J191 = SUM(J175:K190)`
- `TOTAL LIQUID CASH IN CMV` = TOTAL AMOUNT − (TOTAL A/C BALANCE + TOTAL DEBT/EXP) → `F193 = F191 − (H191 + J191)`
- `CASH (counted)`    = Σ(CASH column), OMR entries converted to AED at the rate → `L194 = SUM(L175:M193)`
- `CASH EXTRA`        = CASH counted − Total Liquid Cash                        → `F195 = L194 − F193`

Worked example (matches the sheet exactly):
`86,300 − (29,149 + 39,329) = 17,822` liquid; `17,756 − 17,822 = −66` extra (short).

### Currency handling (decided)

- AED and OMR are shown **separately** for display (OMR rows carry an "OMR" tag).
- **The reconciliation replicates the workbook exactly:** the CDR OMR balance (3,612)
  is added into the A/C BALANCE total **raw / unconverted**, because cell `H191`
  sums it directly. This is required to reproduce 29,149 → 17,822. We match the
  workbook math rather than "correcting" it.
- Only the **CASH column** converts OMR→AED, using the configurable rate
  (`250 OMR × 9.5238 = 2,381`).
- The OMR→AED rate is a Setting (`omr_to_aed_rate`, default `9.5238`).

## Decisions

- **Format:** live in-app page **+** Print / PDF export.
- **Data source:** auto-filled from existing app data, with **per-row editable overrides**.
- **Persistence:** **save dated snapshots** (one record per `calc_date`,
  `updateOrCreate`), so any past day can be reopened and reprinted. Modeled on the
  existing `CashCount` feature.
- **Placement:** **Books → Final Calculation** in the sidebar.

## Architecture

### Data model — `final_calculations` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `calc_date` | date, unique | the snapshot date |
| `data` | json | full structured row figures as saved (see below) |
| `total_amount` | decimal(15,2) | computed, stored for the history list |
| `total_ac_balance` | decimal(15,2) | computed |
| `total_debt_exp` | decimal(15,2) | computed |
| `liquid_cash` | decimal(15,2) | computed |
| `cash_counted` | decimal(15,2) | computed |
| `cash_extra` | decimal(15,2) | computed |
| `remarks` | text nullable | |
| `created_by` | fk users | |
| timestamps | | |

`data` JSON shape (frozen point-in-time values):

```json
{
  "dws_bal": 14345,
  "borrowed": 71955,
  "daily_credit_debt": 39329,
  "daily_credit_cash": 15375,
  "banks": [
    {"key": "rak", "name": "RAK BANK ACCOUNT", "balance": 7602, "currency": "AED", "month_exp": 0},
    {"key": "adcb", "name": "ADCB BANK ACCOUNT", "balance": 12061, "currency": "AED", "month_exp": 0},
    {"key": "fab", "name": "FAB BANK ACCOUNT", "balance": 2476, "currency": "AED"},
    {"key": "dib", "name": "DIB BANK ACCOUNT", "balance": 1398, "currency": "AED"},
    {"key": "cdr", "name": "CDR ACCOUNT (1050697)", "balance": 3612, "currency": "OMR", "is_customs": true}
  ],
  "salary_deposit": 2000,
  "extra_rows": [],
  "cash_lines": [
    {"label": "Daily Credit", "aed": 15375, "omr": 0},
    {"label": "RAK A/C Exp", "aed": 0, "omr": 250}
  ],
  "omr_rate": 9.5238,
  "remarks": null
}
```

- `banks[].month_exp` feeds the **DEBT/EXP** column only (RAK/ADCB monthly expense = 0).
- The **CASH** column is modeled solely by `cash_lines` (each with optional `aed` and
  `omr`; OMR converts at `omr_rate`). This is the single source for the cash total —
  there is no separate per-bank cash field, to avoid double-counting.
- `extra_rows`: optional user-added manual rows `{label, amount, ac_balance, debt_exp}`
  for anything not covered by the fixed rows (folded into the relevant column totals).

`Model`: `App\Models\FinalCalculation` (fillable + casts, `Auditable`, `auditLabel`).

### `FinalCalculationService`

Builds the **auto-filled defaults** for a given date (used when no snapshot exists yet,
or as the "recompute from live data" baseline):

- `dws_bal`           ← `BalanceService::cashBalance()`
- `borrowed`          ← `LedgerEntry::ofType(borrowed)` outstanding = Σ `balance_amount` where `status != returned`
- `daily_credit_debt` ← `LedgerEntry::ofType(daily_credit)` outstanding = Σ `balance_amount` where `status != returned`
- `banks`             ← `BankService::balances()` (name, balance, is_customs); CDR flagged OMR
- `month_exp` per bank ← customs + gov fees paid from that bank in the calc month
- manual rows (`daily_credit_cash`, `salary_deposit`, `cash_lines`) default to 0 / empty
- `omr_rate`          ← `Setting::get('omr_to_aed_rate', 9.5238)`

Also exposes `compute(array $data): array` — pure function returning the six totals
from a `data` payload, so the same math runs server-side (on save) and is unit-testable.

### `FinalCalculationController`

- `index(Request)` — `?date=` (default today). If a snapshot exists for the date, load
  its `data`; else build live defaults via the service. Pass `data`, computed totals,
  and a `history` list (recent snapshots). Renders `Books/FinalCalculation/Index`.
- `store(Request)` — validate `calc_date` + `data`; recompute totals server-side via
  `service->compute()`; `updateOrCreate(['calc_date' => …], [...])`. Role-guarded
  (`super_admin,admin,accountant`), matching `cash-count.store`.
- `pdf(FinalCalculation)` — render `resources/views/final-calculation/pdf.blade.php`
  reproducing the colored one-page layout; download.

### Routes (`routes/web.php`, inside the auth group near Books)

```
GET  books/final-calculation                 → index   (name: final-calc.index)
POST books/final-calculation                 → store   (role-guarded) (name: final-calc.store)
GET  books/final-calculation/{finalCalculation}/pdf → pdf (name: final-calc.pdf)
```

### Frontend — `resources/js/Pages/Books/FinalCalculation/Index.jsx`

- **Header:** title, date picker (reload on change), Save button, Print/PDF button,
  a small "Recompute from live data" action to refresh auto values into the form.
- **Main table:** reproduces the sheet layout with four value columns
  (AMOUNT / A/C BALANCE / DEBT-EXP / CASH). Every figure is an editable number input,
  pre-filled from `data`. Section grouping matches the sheet (top block, banks block,
  expenses/salary block, totals block).
- **Live totals:** TOTAL AMOUNT, TOTAL A/C BALANCE, TOTAL DEBT/EXP, and the two green
  boxes (TOTAL LIQUID CASH, CASH EXTRA) recompute in the browser as inputs change,
  using the same formula as `service->compute()`. Cash-column OMR entries convert with
  the `omr_rate` field.
- **History:** list of saved snapshots (date, liquid cash, cash extra) with links to
  reopen / download PDF.
- **AED/OMR:** OMR rows show an "OMR" tag; a single `omr_rate` input drives the CASH
  column conversion.

### Nav

Add to the `books` group in `AuthenticatedLayout.jsx`:
`{ label: 'Final Calculation', href: route('final-calc.index'), icon: '∑', active: current('final-calc.*') }`

## Testing

- **Unit** (`FinalCalculationService::compute`): feed the workbook's exact `data`
  payload; assert `total_amount=86300`, `total_ac_balance=29149`, `total_debt_exp=39329`,
  `liquid_cash=17822`, `cash_counted=17756`, `cash_extra=-66`. This locks the formulas.
- **Feature:** `index` returns live defaults when no snapshot; `store` persists and
  recomputes totals; reopening a saved date returns the stored `data`; `store` is
  role-guarded (viewer forbidden).
- **Auto-fill:** with seeded transactions/ledger/banks, the service's defaults match
  `BalanceService`/`BankService`/`LedgerEntry` outputs.

## Out of scope

- Rebuilding the full daily worksheet (the blocks that feed DWS balance, borrowed, and
  daily-credit totals) — we consume their computed results, not their sub-ledgers.
- Multi-currency accounting beyond the single OMR→AED rate used for the cash column and
  the CDR display tag.
- Automatic OMR conversion of the CDR A/C-balance figure (deliberately replicating the
  workbook's raw addition).
