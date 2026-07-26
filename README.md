# CMV Shipping — Accounts Management System

A modern, multi-user accounting web app that replaces CMV Shipping's Excel workbook.
Built API-first so a Flutter mobile app can attach later with no rework.

**Stack:** Laravel 13 · MySQL · Inertia + React 19 · Tailwind · Recharts · Sanctum · PhpSpreadsheet · DomPDF · Pest.

---

## Phase 1 features (built)

- **Auth & roles** — Super Admin, Admin, Accountant, Read-only (enforced on web + API)
- **Dashboard** — today/monthly income & expenses, cash/bank/credit balances, profit, charts
- **Transaction entry** — all workbook fields, multi-row expenses & commissions, **auto-calculated** totals
- **Transactions list** — search + date/customer/method/amount filters
- **Masters** — customers, references, vehicles, expense categories, payment methods, banks, account heads
- **Credit / receivables** — outstanding tracking + record payments
- **Excel import** — `.xlsx/.xlsm/.csv`, preview + validation, idempotent, auto-creates masters
- **Reports** — daily, monthly, customer-wise, outstanding-credit — with **PDF / Excel / Print**
- **JSON API** — Sanctum tokens, transactions CRUD, master dropdowns (Flutter-ready)

## The calculation engine

All money math lives in `app/Services/TransactionCalculator.php` (bcmath, money-safe) and is
mirrored in `resources/js/lib/calc.js` for live on-screen totals. Formulas:

```
Total Amount = Customs + Gov Fees + Profit + VAT
Grand Total  = Total Amount + commissions charged to customer
Net Profit   = Profit - Expenses - commissions paid to references
```

---

## Local setup

```bash
composer install
npm install
cp .env.example .env          # then set DB_* for MySQL
php artisan key:generate
php artisan migrate --seed     # creates schema + default masters + super admin
npm run build                  # or: npm run dev (hot reload)
php artisan serve
```

### Default login

| Email | Password | Role |
|-------|----------|------|
| `admin@cmvshipping.com` | `cmv12345` | Super Admin |

> Change this immediately in production (set `SEED_ADMIN_PASSWORD` before seeding, or update via the app).

### Importing your workbook

Log in → **Import Excel** → upload `ACCOUNT WORKBOOK.xlsm` → review the preview → **Confirm & Import**.
The importer detects per-day sheets, maps columns automatically, skips duplicates, and creates any
missing customers/references/vehicles.

---

## Tests

```bash
./vendor/bin/pest      # PHP: calculation engine, importer, reports, API, roles
npm run test           # JS: live-totals parity with the PHP engine
```

## Deployment

See [docs/DEPLOY_HOSTINGER.md](docs/DEPLOY_HOSTINGER.md).

## Design & plan

- Spec: [docs/superpowers/specs/2026-07-26-cmv-accounts-phase1-design.md](docs/superpowers/specs/2026-07-26-cmv-accounts-phase1-design.md)
- Plan: [docs/superpowers/plans/2026-07-26-cmv-accounts-phase1.md](docs/superpowers/plans/2026-07-26-cmv-accounts-phase1.md)
