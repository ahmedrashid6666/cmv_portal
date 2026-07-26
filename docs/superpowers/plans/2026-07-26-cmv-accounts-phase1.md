# CMV Shipping Accounts — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a working, multi-user Laravel + MySQL accounts system that replaces CMV Shipping's Excel workbook — transaction entry with auto-calculation, masters, dashboard, historical import, and core reports — exposed API-first so a Flutter app can attach later.

**Architecture:** Laravel 11/12 (PHP 8.2+) monolith with Inertia + React + Tailwind for the web UI and a parallel Sanctum-authenticated JSON API. All money logic lives in framework-agnostic service classes (`TransactionCalculator`, `BalanceService`, `WorkbookImporter`) that both the web controllers and API controllers call, so web and mobile behave identically. MySQL via Eloquent; soft-deletes on transactions.

**Tech Stack:** Laravel, MySQL 8/9, Inertia.js, React 19, Tailwind, Recharts, Laravel Sanctum, Laravel Excel (PhpSpreadsheet), DomPDF, Pest (tests).

## Global Constraints

- PHP ≥ 8.2 (local is 8.5.4). Target the current stable Laravel that supports PHP 8.5.
- Database: MySQL. All monetary columns `DECIMAL(12,2)`. Single currency AED in Phase 1.
- Every computed value (totals, VAT, grand total, net profit, balances, credit outstanding) is derived by `TransactionCalculator` / `BalanceService` — never hand-entered, never duplicated in a controller.
- VAT rate is read from `settings` (default `0`). Never hardcode 5% or 0%.
- Authorization enforced by Policies + a `role` middleware, applied identically to web and API routes. Four roles: `super_admin`, `admin`, `accountant`, `read_only`.
- Tests use Pest. Every task ends green. Commit after every task.
- Money in code: integer-safe — use `bcmath`/`BigDecimal` or cast to `DECIMAL` strings; never sum floats directly for stored totals.

---

## Milestone M0 — Project Foundation

### Task 0.1: Scaffold Laravel app with Inertia + React

**Files:**
- Create: whole Laravel skeleton at repo root (`app/`, `routes/`, `resources/js/`, etc.)
- Create: `.gitignore` (Laravel default), `.env`

**Steps:**
- [ ] **Step 1:** From repo root, scaffold into a temp dir then move (root already has `.git`, `.claude`, `docs`):
  ```bash
  composer create-project laravel/laravel cmv_tmp
  rsync -a cmv_tmp/ ./ && rm -rf cmv_tmp
  ```
- [ ] **Step 2:** Install Breeze with React+Inertia scaffolding:
  ```bash
  composer require laravel/breeze --dev
  php artisan breeze:install react
  npm install
  ```
- [ ] **Step 3:** Configure `.env` for MySQL:
  ```
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=cmv_accounts
  DB_USERNAME=root
  DB_PASSWORD=
  ```
  Create the database: `mysql -uroot -e "CREATE DATABASE IF NOT EXISTS cmv_accounts"`
- [ ] **Step 4:** Verify it boots: `php artisan migrate` then `php artisan test` (Breeze default tests pass).
- [ ] **Step 5:** Install core packages:
  ```bash
  composer require laravel/sanctum maatwebsite/excel barryvdh/laravel-dompdf
  php artisan install:api
  ```
- [ ] **Step 6:** Switch test runner to Pest: `composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies && ./vendor/bin/pest --init`. Run `./vendor/bin/pest` — green.
- [ ] **Step 7:** Commit: `git add -A && git commit -m "chore: scaffold Laravel + Breeze/React + core packages"`

### Task 0.2: Roles on the user model

**Files:**
- Modify: `database/migrations/xxxx_create_users_table.php` (add `role`)
- Create: `app/Enums/Role.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/UserRoleTest.php`

**Interfaces:**
- Produces: `Role` enum (`SUPER_ADMIN`, `ADMIN`, `ACCOUNTANT`, `READ_ONLY` → string values `super_admin` etc.); `User::hasRole(Role): bool`, `User::role` cast to `Role`.

- [ ] **Step 1: Failing test** `tests/Feature/UserRoleTest.php`:
  ```php
  use App\Models\User; use App\Enums\Role;
  it('exposes a typed role', function () {
      $u = User::factory()->create(['role' => Role::ACCOUNTANT->value]);
      expect($u->role)->toBe(Role::ACCOUNTANT);
      expect($u->hasRole(Role::ACCOUNTANT))->toBeTrue();
      expect($u->hasRole(Role::ADMIN))->toBeFalse();
  });
  ```
- [ ] **Step 2:** Run `./vendor/bin/pest --filter=UserRoleTest` → FAIL.
- [ ] **Step 3:** Create `app/Enums/Role.php`:
  ```php
  <?php
  namespace App\Enums;
  enum Role: string {
      case SUPER_ADMIN = 'super_admin';
      case ADMIN = 'admin';
      case ACCOUNTANT = 'accountant';
      case READ_ONLY = 'read_only';
      public function label(): string {
          return ucwords(str_replace('_', ' ', $this->value));
      }
  }
  ```
  Add to users migration: `$table->string('role')->default(Role::READ_ONLY->value);`. In `User.php` add `'role' => Role::class` to `casts()` and:
  ```php
  public function hasRole(Role $r): bool { return $this->role === $r; }
  ```
- [ ] **Step 4:** `php artisan migrate:fresh` then `./vendor/bin/pest --filter=UserRoleTest` → PASS.
- [ ] **Step 5:** Commit: `git commit -am "feat: typed roles on user model"`

### Task 0.3: Role middleware + policy base

**Files:**
- Create: `app/Http/Middleware/EnsureRole.php`
- Modify: `bootstrap/app.php` (register alias `role`)
- Test: `tests/Feature/RoleMiddlewareTest.php`

**Interfaces:**
- Produces: route middleware `role:super_admin,admin` — passes if the user's role is in the list, else 403.

- [ ] **Step 1: Failing test:**
  ```php
  it('blocks users without the required role', function () {
      Route::middleware(['web','auth','role:super_admin'])->get('/_t', fn()=>'ok');
      $this->actingAs(User::factory()->create(['role'=>'accountant']))->get('/_t')->assertForbidden();
      $this->actingAs(User::factory()->create(['role'=>'super_admin']))->get('/_t')->assertOk();
  });
  ```
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement `EnsureRole` (reads `$request->user()->role->value`, aborts 403 if not in `$roles`), register alias in `bootstrap/app.php` `->withMiddleware(fn($m)=>$m->alias(['role'=>EnsureRole::class]))`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

---

## Milestone M1 — Data Model & Masters

### Task 1.1: Master tables migrations + models

**Files:**
- Create migrations + Eloquent models for: `customers`, `references`, `vehicles`, `expense_categories`, `payment_methods`, `banks`, `account_heads`, `settings`.
- Test: `tests/Feature/MastersModelTest.php`

**Schema (money = `decimal(12,2)`):**
- `customers`: name, contact nullable, notes nullable, opening_balance default 0
- `references`: name, contact nullable
- `vehicles`: number (unique), notes nullable
- `expense_categories`: name (unique)
- `payment_methods`: name (unique), type enum(`cash`,`bank`,`credit`,`other`)
- `banks`: name, account_no nullable, opening_balance default 0
- `account_heads`: name, type enum(`asset`,`liability`,`income`,`expense`,`equity`)
- `settings`: key (unique), value (text)

**Interfaces:**
- Produces: models `Customer, Reference, Vehicle, ExpenseCategory, PaymentMethod, Bank, AccountHead, Setting` with mass-assignable fields; `Setting::get(string $key, $default)`, `Setting::put(string $key, $value)`.

- [ ] **Step 1: Failing test** — create one customer, one payment method, assert persistence + `Setting::get('vat_rate','0')` returns `'0'` after `Setting::put('vat_rate','0')`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Write all migrations + models. `Setting` helpers:
  ```php
  public static function get(string $k, $d=null){ return static::where('key',$k)->value('value') ?? $d; }
  public static function put(string $k,$v){ return static::updateOrCreate(['key'=>$k],['value'=>(string)$v]); }
  ```
- [ ] **Step 4:** `php artisan migrate` → tests PASS.
- [ ] **Step 5:** Commit.

### Task 1.2: Seeder for default masters + settings + users

**Files:**
- Create: `database/seeders/DefaultDataSeeder.php`, factories for each master.
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/SeederTest.php`

- [ ] **Step 1: Failing test:** after `$this->seed(DefaultDataSeeder::class)`, assert payment methods include Cash/Bank/Credit/Card/Cheque/Online Transfer; expense categories include ZAJEL/Courier/Typing/Office/Fuel/Salik/Parking/Printing/Miscellaneous; `vat_rate` setting = `'0'`; a `super_admin` user exists.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Write seeder: the six payment methods (with correct `type`), the expense categories, settings (`vat_rate=0`, `currency=AED`, `company_name=CMV Shipping`), and one super admin (`admin@cmvshipping.local`, password from env or `password`).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

### Task 1.3: Transaction + children migrations & models

**Files:**
- Create migrations/models: `transactions`, `transaction_expenses`, `transaction_commissions`, `credit_payments`.
- Test: `tests/Feature/TransactionModelTest.php`

**Schema:**
- `transactions`: transaction_date (date), invoice_no nullable, boe_no nullable, customer_id FK, reference_id FK nullable, vehicle_id FK nullable, customs_fees decimal, gov_fees decimal, profit decimal, vat_rate decimal(5,2), vat_amount decimal, total_amount decimal, payment_method_id FK, credit_amount decimal default 0, remarks text nullable, attachment_path nullable, created_by FK users, timestamps, softDeletes.
- `transaction_expenses`: transaction_id FK cascade, expense_category_id FK nullable, description, amount decimal.
- `transaction_commissions`: transaction_id FK cascade, label nullable, amount decimal, type enum(`charged_to_customer`,`paid_to_reference`), reference_id FK nullable.
- `credit_payments`: transaction_id FK cascade, payment_date date, amount decimal, payment_method_id FK, note nullable, created_by FK.

**Interfaces:**
- Produces: `Transaction` with relations `expenses()`, `commissions()`, `creditPayments()`, `customer()`, `reference()`, `vehicle()`, `paymentMethod()`; children models with `belongsTo`.

- [ ] **Step 1: Failing test:** create a transaction with 2 expenses + 2 commissions + 1 credit payment via relations; assert counts and cascade delete removes children.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Write migrations (FKs, cascade on children) + models with relations + factories.
- [ ] **Step 4:** `php artisan migrate` → PASS.
- [ ] **Step 5:** Commit.

---

## Milestone M2 — Calculation Engine (highest-risk, full TDD)

### Task 2.1: TransactionCalculator service

**Files:**
- Create: `app/Services/TransactionCalculator.php`
- Test: `tests/Unit/TransactionCalculatorTest.php`

**Interfaces:**
- Produces: pure methods operating on a value object / array (no DB):
  - `vatAmount(string $taxableBase, string $rate): string`
  - `totalAmount(customs, gov, profit, vat): string`
  - `grandTotal(totalAmount, array $commissions): string` (adds `charged_to_customer` only)
  - `totalExpenses(array $expenses): string`
  - `commissionPayable(array $commissions): string` (`paid_to_reference` only)
  - `netProfit(profit, totalExpenses, commissionPayable): string`
  - `creditOutstanding(creditAmount, array $payments): string`
  All money as decimal strings via `bcadd/bcmul/bcsub`, scale 2.

- [ ] **Step 1: Failing tests** covering the real workbook rows:
  ```php
  use App\Services\TransactionCalculator as C;
  it('matches workbook row 7 (customs 295, profit 50, vat 0, comm 25 to customer)', function () {
      $c = new C();
      $vat = $c->vatAmount('345.00','0');          // taxable=customs+gov+profit
      expect($vat)->toBe('0.00');
      $total = $c->totalAmount('295.00','0.00','50.00',$vat);
      expect($total)->toBe('345.00');
      $grand = $c->grandTotal($total, [['type'=>'charged_to_customer','amount'=>'25.00']]);
      expect($grand)->toBe('370.00');
  });
  it('computes net profit net of expenses and payable commission', function () {
      $c = new C();
      expect($c->totalExpenses([['amount'=>'27.00'],['amount'=>'3.00']]))->toBe('30.00');
      expect($c->commissionPayable([['type'=>'paid_to_reference','amount'=>'10.00']]))->toBe('10.00');
      expect($c->netProfit('50.00','30.00','10.00'))->toBe('10.00');
  });
  it('computes credit outstanding after partial payments', function () {
      $c = new C();
      expect($c->creditOutstanding('200.00', [['amount'=>'50.00'],['amount'=>'30.00']]))->toBe('120.00');
  });
  it('applies a non-zero vat rate from settings', function () {
      $c = new C();
      expect($c->vatAmount('100.00','5'))->toBe('5.00');
  });
  ```
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement with bcmath (`bcscale(2)`), summing arrays, filtering by `type`.
- [ ] **Step 4:** Run → all PASS.
- [ ] **Step 5:** Commit.

### Task 2.2: Persist derived fields on save (observer)

**Files:**
- Create: `app/Observers/TransactionObserver.php`
- Modify: `app/Models/Transaction.php` (`#[ObservedBy]`), add `grand_total`, `net_profit` cached columns (migration).
- Test: `tests/Feature/TransactionSaveTest.php`

**Interfaces:**
- Consumes: `TransactionCalculator`.
- Produces: on `saving`, sets `vat_amount`, `total_amount`; on `saved` (after children exist) recomputes `grand_total`, `net_profit`. Provide `Transaction::recomputeTotals()` called from controllers after syncing children.

- [ ] **Step 1: Failing test:** create a transaction + children through the model, call `recomputeTotals()`, assert `grand_total`/`net_profit` match calculator output.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add migration for `grand_total`,`net_profit` (decimal, default 0); implement `recomputeTotals()` that loads relations and writes cached columns; observer sets `total_amount`/`vat_amount` from inputs.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

### Task 2.3: BalanceService (cash/bank/credit)

**Files:**
- Create: `app/Services/BalanceService.php`
- Test: `tests/Feature/BalanceServiceTest.php`

**Interfaces:**
- Produces: `cashBalance(): string`, `bankBalance(): string`, `creditOutstandingTotal(): string`, `todaysIncome(Carbon): string`, `todaysExpenses(Carbon): string`, `monthlyIncome(int $y,int $m)`, `monthlyExpenses(...)`, `totalProfit()`. Cash/bank = opening balances (banks + cash-type methods) + receipts − payments derived from transactions and credit_payments, bucketed by payment method `type`.

- [ ] **Step 1: Failing test:** seed masters; create 2 transactions (one Cash, one Credit with a partial credit payment via Bank) + expenses; assert `cashBalance`, `bankBalance`, `creditOutstandingTotal`, `todaysIncome`, `todaysExpenses` equal hand-computed values.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement with query aggregates grouped by payment method type; expenses reduce cash/bank by their paid method (Phase 1: expenses reduce the transaction's payment bucket).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

---

## Milestone M3 — Transactions (web + API)

### Task 3.1: FormRequest validation + store/update service

**Files:**
- Create: `app/Http/Requests/TransactionRequest.php`, `app/Services/TransactionWriter.php`
- Test: `tests/Feature/TransactionWriterTest.php`

**Interfaces:**
- Produces: `TransactionWriter::create(array $data): Transaction`, `::update(Transaction, array): Transaction` — wraps in a DB transaction, syncs expenses/commissions, calls `recomputeTotals()`. Validation: date required; customer_id exists; money fields numeric ≥ 0; commissions[].type in enum; credit_amount ≤ grand_total when method = credit.

- [ ] **Step 1: Failing tests:** (a) creating with nested expenses/commissions persists all and computes totals; (b) validation rejects negative customs and unknown customer.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement request rules + writer (uses `DB::transaction`, `->expenses()->delete()` then re-create on update).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

### Task 3.2: Web controller + Inertia pages (entry, list)

**Files:**
- Create: `app/Http/Controllers/TransactionController.php`; routes in `routes/web.php` (resource, `role:super_admin,admin,accountant` for writes).
- Create React: `resources/js/Pages/Transactions/Index.jsx`, `Form.jsx`; components `ExpenseRows.jsx`, `CommissionRows.jsx`, `LiveTotals.jsx`.
- Test: `tests/Feature/TransactionWebTest.php`

**Interfaces:**
- Consumes: `TransactionWriter`, `TransactionRequest`. Form posts nested `expenses[]`, `commissions[]`. `LiveTotals` mirrors `TransactionCalculator` in JS (fed the same inputs) for instant on-screen totals; server remains source of truth on save.

- [ ] **Step 1: Failing feature test:** an accountant POSTs a full transaction → 302/redirect, row + children exist, totals correct; a read_only user POST → 403; index page renders with the created row.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement controller (index with filters, create/store/edit/update/destroy), Inertia pages. Build the JS live-total mirror as a small pure module `resources/js/lib/calc.js` with a Vitest test asserting parity with the PHP examples (grand total 370 case).
- [ ] **Step 4:** Run pest + `npm run test` (Vitest) → PASS.
- [ ] **Step 5:** Commit.

### Task 3.3: Search & filters on list

**Files:**
- Modify: `TransactionController@index` (query filters), `Index.jsx` (filter bar).
- Test: `tests/Feature/TransactionFilterTest.php`

- [ ] **Step 1: Failing test:** seed 3 transactions; filter by date range, by customer, by invoice_no, by payment method, by amount range → correct subsets returned.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement `when()` filter chain on invoice_no, boe_no, customer_id, vehicle_id, reference_id, payment_method_id, date range, amount range, profit range.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

### Task 3.4: JSON API for transactions (Sanctum)

**Files:**
- Create: `app/Http/Controllers/Api/TransactionApiController.php`, `app/Http/Resources/TransactionResource.php`; `routes/api.php` under `auth:sanctum` + `role:` middleware.
- Test: `tests/Feature/Api/TransactionApiTest.php`

**Interfaces:**
- Consumes: same `TransactionWriter` + `TransactionRequest` (shared validation). Produces REST: `GET/POST/PUT/DELETE /api/transactions`, token via `POST /api/login`.

- [ ] **Step 1: Failing test:** issue Sanctum token, POST a transaction via API → 201 with computed grand_total in JSON; unauthenticated → 401; read_only token POST → 403.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement thin API controller reusing the writer; resource serializes computed fields.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

### Task 3.5: Attachment upload

**Files:**
- Modify: `TransactionWriter`, `TransactionRequest`, `Form.jsx`.
- Test: `tests/Feature/TransactionAttachmentTest.php`

- [ ] **Step 1: Failing test:** `Storage::fake('public')`; POST with a fake PDF → file stored, `attachment_path` set.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Validate mimes pdf/jpg/png ≤ 5MB, store on `public` disk, save path.
- [ ] **Step 4:** Run → PASS. `php artisan storage:link`.
- [ ] **Step 5:** Commit.

---

## Milestone M4 — Credit / Receivables

### Task 4.1: Record credit payment

**Files:**
- Create: `app/Http/Controllers/CreditPaymentController.php`, `resources/js/Pages/Credits/Index.jsx`; route (roles: super_admin, admin, accountant).
- Test: `tests/Feature/CreditPaymentTest.php`

**Interfaces:**
- Consumes: `TransactionCalculator::creditOutstanding`. Produces: outstanding list (transactions where outstanding > 0) + `store` a payment (validates amount ≤ outstanding).

- [ ] **Step 1: Failing test:** credit transaction of 200; post payment of 120 → outstanding 80; post 100 → rejected (exceeds outstanding); post 80 → outstanding 0, no longer in list.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement list query (compute outstanding), store with validation.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

---

## Milestone M5 — Masters CRUD (web + API)

### Task 5.1: Generic master CRUD

**Files:**
- Create controllers + Inertia pages for each master (`customers`, `references`, `vehicles`, `expense_categories`, `payment_methods`, `banks`, `account_heads`). Writes gated `role:super_admin,admin`; payment_methods/account_heads/settings gated `role:super_admin`.
- Create: `app/Http/Controllers/Api/MasterApiController.php` (read endpoints for dropdowns: customers, references, vehicles, categories, payment methods).
- Test: `tests/Feature/MastersCrudTest.php`, `tests/Feature/Api/MastersApiTest.php`

- [ ] **Step 1: Failing tests:** admin creates/edits/deletes a customer; accountant is blocked from creating a payment method (403); API returns customer list JSON for an authenticated token.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement resource controllers + simple index/create/edit React pages sharing a `<MasterTable>` component; API read endpoints.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit.

---

## Milestone M6 — Dashboard

### Task 6.1: Dashboard data controller

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`; `resources/js/Pages/Dashboard.jsx` (replace Breeze default).
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `BalanceService`. Produces Inertia props: todaysIncome, todaysExpenses, cashBalance, bankBalance, creditBalance, totalProfit, monthlyIncome, monthlyExpenses, totalCustomers, pendingCreditsCount, recentTransactions[], and chart series: `dailyIncomeVsExpense` (last 14 days), `paymentMethodBreakdown`.

- [ ] **Step 1: Failing test:** seed data, GET `/dashboard` as any authed user → props contain correct `cashBalance`, `todaysIncome`, `pendingCreditsCount`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement controller aggregations + `Dashboard.jsx` with stat cards and two Recharts charts (`npm i recharts`).
- [ ] **Step 4:** Run → PASS. Manual visual check via `npm run dev` + `php artisan serve`.
- [ ] **Step 5:** Commit.

---

## Milestone M7 — Import & Reports

### Task 7.1: WorkbookImporter — parser (dry run)

**Files:**
- Create: `app/Services/WorkbookImporter.php`
- Test: `tests/Unit/WorkbookImporterTest.php` + fixture `tests/fixtures/sample_day.xlsx`

**Interfaces:**
- Produces: `parse(string $path): ImportPreview` where `ImportPreview` has `rows[]` (mapped, typed), `errors[]` (row → message), `newCustomers[]`, `newReferences[]`, `newVehicles[]`. Detects per-day sheets by date-formatted sheet name; maps columns A→U per the known layout (Sl No, Invoice, BOE, Customer, Reference, Vehicle, Customs, GovFees, Profit, VAT, Total, PaymentMode, Credit, ExpenseDesc, ExpenseAmount, Comm1, Comm2, GrandTotal). Skips header/blank/total rows.

- [ ] **Step 1:** Create a small `.xlsx` fixture mirroring the real sheet (5 rows incl. one with expense + one with commission). Build it in the test `beforeAll` via PhpSpreadsheet so it's reproducible.
- [ ] **Step 2: Failing test:** `parse()` returns 5 rows, correct customs/profit/total per row, detects the commission row and expense row, flags an intentionally-bad row (non-numeric customs) in `errors`.
- [ ] **Step 3:** Run → FAIL.
- [ ] **Step 4:** Implement parser with Laravel Excel / PhpSpreadsheet reading; date-sheet detection; column mapping; validation.
- [ ] **Step 5:** Run → PASS. Commit.

### Task 7.2: WorkbookImporter — commit (idempotent)

**Files:**
- Modify: `WorkbookImporter` (`commit(ImportPreview): ImportResult`), reuse `TransactionWriter`.
- Test: `tests/Feature/WorkbookImportCommitTest.php`

**Interfaces:**
- Produces: `commit()` creates missing masters, inserts transactions (skipping any where `invoice_no` already exists for that date), returns counts. Re-running the same file inserts 0 new rows.

- [ ] **Step 1: Failing test:** commit fixture → N transactions created, masters auto-created; commit again → 0 new (idempotent).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement commit inside `DB::transaction`, dedupe on (invoice_no, transaction_date).
- [ ] **Step 4:** Run → PASS. Commit.

### Task 7.3: Import UI (upload → preview → commit)

**Files:**
- Create: `app/Http/Controllers/ImportController.php`, `resources/js/Pages/Import/Index.jsx` (role: super_admin, admin).
- Test: `tests/Feature/ImportWebTest.php`

- [ ] **Step 1: Failing test:** upload fixture → preview JSON returned (rows + errors); confirm → rows committed.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Two-step controller: `preview` (store temp file, return `ImportPreview`), `commit`. React page shows preview table with error highlighting and a Confirm button.
- [ ] **Step 4:** Run → PASS. Commit.

### Task 7.4: Report engine + 4 reports

**Files:**
- Create: `app/Services/Reports/ReportBuilder.php`; controllers `DailyReport`, `MonthlyReport`, `CustomerReport`, `OutstandingCreditReport` (or one `ReportController` with type param); `resources/js/Pages/Reports/*`.
- Test: `tests/Feature/ReportsTest.php`

**Interfaces:**
- Produces: each report returns `{ rows[], totals{}, chart[] }` filtered by date range / customer. Shared builder pulls from transactions + `BalanceService`.

- [ ] **Step 1: Failing tests:** Daily report for a date returns that day's transactions + correct totals (income, expenses, profit); Monthly aggregates by day; Customer-wise groups by customer with totals; Outstanding Credit lists only unpaid balances with correct outstanding.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement builder + controllers + React report pages (filters, totals row, one Recharts chart each).
- [ ] **Step 4:** Run → PASS. Commit.

### Task 7.5: Export — PDF / Excel / Print

**Files:**
- Create: `app/Exports/ReportExcelExport.php` (Laravel Excel), `resources/views/reports/pdf.blade.php` (DomPDF); export routes.
- Test: `tests/Feature/ReportExportTest.php`

- [ ] **Step 1: Failing test:** `GET /reports/daily/export?format=xlsx` returns a downloadable xlsx (200, correct content-type); `format=pdf` returns a PDF response.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement Excel export class + Blade PDF template; Print = same PDF/print-CSS view. Wire buttons in report pages.
- [ ] **Step 4:** Run → PASS. Commit.

---

## Milestone M8 — Ship

### Task 8.1: End-to-end smoke + seed demo + docs

**Files:**
- Create: `docs/DEPLOY_HOSTINGER.md`, `README.md`.
- Test: `tests/Feature/SmokeTest.php`

- [ ] **Step 1:** Full suite green: `./vendor/bin/pest && npm run test && npm run build`.
- [ ] **Step 2:** Write `DEPLOY_HOSTINGER.md`: upload build, set `.env` (MySQL creds from hPanel), `composer install --no-dev`, `php artisan migrate --force`, `php artisan storage:link`, point domain docroot to `/public`, set up daily `mysqldump` cron. Note: shared hosting runs the built assets (`npm run build`) — no Node needed at runtime; VPS only needed if you later add queues.
- [ ] **Step 3:** `README.md`: local setup, roles, how to import the workbook.
- [ ] **Step 4:** Commit + tag `v0.1.0-phase1`.

---

## Self-Review — Spec Coverage Map

| Spec section | Covered by |
|---|---|
| Auth + 4 roles + forgot password | M0 (Breeze gives login/forgot), 0.2–0.3, enforced throughout |
| Dashboard (figures + 2 charts) | M6 / Task 6.1 (remaining charts = Phase 2, per spec) |
| Transaction entry (all fields, income, payment) | M3 / 3.1–3.2 |
| Multiple expense rows | 1.3, 3.1–3.2 |
| Commission 1/2 + auto total + type | 1.3, 2.1, 3.1 |
| Automatic calculations | M2 (calculator, balances, observer) |
| Masters management | M5 |
| Search & filters | 3.3 |
| Reports (Daily/Monthly/Customer/Outstanding) + PDF/Excel/Print | 7.4–7.5 |
| Import .xlsm/.xlsx/.csv (bulk historical) | 7.1–7.3 |
| Credit tracking + repayments | 1.3, M4 |
| API-first (Flutter-ready) | 3.4, 5.1 (API), Sanctum in M0 |
| Attachments / invoice upload | 3.5 |
| Hosting on Hostinger + SQL | 8.1 deploy doc |

**Deferred to later phases (explicitly out of scope, per spec §9):** dynamic no-code fields, audit log/edit history/recycle-bin UI, backup/restore UI, remaining reports & charts, notifications, all Phase-4 modules. Backups covered operationally via cron in 8.1.

**Placeholder scan:** none — every task has concrete files, code, and test assertions.
**Type consistency:** `TransactionCalculator` method names used identically in 2.1, 2.2, 3.2 (JS mirror), 4.1, 7.4. `Role` enum values consistent across 0.2, 0.3, all middleware.
