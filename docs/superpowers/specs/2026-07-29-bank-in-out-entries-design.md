# Bank In/Out Entries — Design

**Date:** 2026-07-29
**Status:** Approved

## Problem

The Bank Accounts page shows each bank's balance derived only from `opening −
customs paid − gov paid`. There is no way to record ad-hoc money movements on a
bank (deposits, charges, transfers). Users need to add manual **In** (deposit)
and **Out** (withdrawal) entries per bank, have them adjust the balance, and see
them listed.

## Decisions (from brainstorming)

- Entries affect **both** the per-bank balance **and** the Dashboard overall
  Bank Balance (real money movement).
- The stored list is shown **inside the In/Out dialog** for each bank.
- `item` is **free text**.
- Deleting an entry is allowed (for corrections); soft delete.
- Amounts are **AED only** (banks have no currency field today).

## Data model

New table `bank_entries`:

| column        | type            | notes                                   |
|---------------|-----------------|-----------------------------------------|
| id            | bigint          |                                         |
| bank_id       | FK → banks      | `cascadeOnDelete`                       |
| entry_date    | date            | form defaults to today                  |
| item          | string          | free text                               |
| description   | string, null    |                                         |
| direction     | string          | `'in'` or `'out'`                       |
| amount        | decimal(12,2)   | always positive                         |
| created_by    | FK → users,null | audit                                   |
| timestamps    |                 |                                         |
| deleted_at    | softDeletes     | consistent with `Transaction`           |

Model `App\Models\BankEntry`:
- `use Auditable, HasFactory, SoftDeletes`
- `belongsTo(Bank)`, `belongsTo(User, 'created_by')` as `creator`
- casts `entry_date` => date, `amount` => decimal:2
- scopes: `in()` / `out()` helpers optional

## Balance math

- `BankService::balances()` — per bank:
  `balance = opening + Σ in − Σ out − customs − gov`.
  Add `net_manual = Σ in − Σ out` computed per bank via grouped sums.
- `BankService::statement()` — include each `BankEntry` as an event:
  In → `debit`, Out → `credit`. Running balance formula becomes
  `balance + debit − credit`; the pre-window rollup into opening uses the same
  signing. This keeps the statement's closing balance equal to the Accounts
  page balance.
- `BalanceService::bankBalance()` — add `+ Σ in − Σ out` across all banks
  (bcmath), so the Dashboard figure matches.

## Endpoints / routes (web, auth)

- `POST bank-accounts/{bank}/entries` → `BankAccountController@storeEntry`
  - validates: `entry_date` (date, required), `item` (required string),
    `description` (nullable string), `direction` (`in|out`) derived from which
    amount is filled, `amount` (numeric > 0). Exactly one of In/Out must be > 0.
  - redirect back to `bank-accounts.index` with success; Inertia post uses
    `preserveState`/`preserveScroll` so the dialog stays open.
- `DELETE bank-accounts/entries/{entry}` → `BankAccountController@destroyEntry`
  - soft delete; redirect back.
- Write actions restricted to roles that can already add entries
  (super_admin / admin / accountant), mirroring existing gates.

## Data passed to the page

`BankService::balances()` continues to return one row per bank, now also
including that bank's `entries` (array, newest first) and `total_in` /
`total_out` so the dialog can render the list and footer without extra fetches.
Expected volume is low (manual entries), so embedding is acceptable in Phase 1.

## UI — `Banks/Accounts.jsx`

- New **"In / Out"** action button on each bank row (beside "Statement"),
  shown only to writers.
- Dialog (bank-scoped), opened from that button:
  - **Add form** (one row): Date (default today) · Item · Description · **In**
    amount · **Out** amount · Save. Fill In *or* Out (client + server validate
    exactly one).
  - **List**: columns **Date · Item · Description · In · Out**, newest first,
    each with a delete control (confirm).
  - **Footer**: Total In · Total Out · Resulting balance.
- Saving posts and refreshes props while keeping the dialog open; list and
  balances update live.

## Testing (Pest feature tests)

- An `in` entry raises the bank balance and the Dashboard `bankBalance()`.
- An `out` entry lowers both.
- Deleting an entry reverts the effect.
- Validation rejects: zero amount, both In and Out filled, negative.
- The bank statement's closing balance equals the Accounts-page balance after
  mixed in/out entries.
- Write endpoints forbidden for a viewer role.

## Out of scope

- Per-entry currency (AED only for now).
- Editing an entry in place (delete + re-add instead).
- Weaving entries into any report other than the bank statement.
