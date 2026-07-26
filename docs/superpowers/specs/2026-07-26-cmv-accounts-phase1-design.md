# CMV Shipping — Accounts Management System (Phase 1 Design)

**Date:** 2026-07-26
**Status:** Approved (design), pending implementation plan
**Scope:** Phase 1 (Core MVP). Phases 2–4 outlined at the end but out of scope for this spec.

---

## 1. Purpose

Replace CMV Shipping's Excel-based accounting (`ACCOUNT WORKBOOK.xlsm`, one sheet per day) with a modern, multi-user web application. The system preserves every existing field and calculation, automates all math, adds role-based access, dashboards, master data, historical import, and core reports. It is built **API-first** so a Flutter mobile app can be added later with no rework.

### Source data (observed)
The workbook uses one worksheet per day (e.g. `01-07-2026`), ~16 transactions/day, plus a `MONTH END CALCULATION` sheet. Each row is one shipment with:

`Sl No → Invoice No → BOE No → Customer → Reference → Vehicle → Customs Fees (CDR) → Other Gov Fees → Profit → VAT (0%) → Total Amount → Payment Mode → Credit Amount → Expense Details → Expense Amount → Commission 1 → Commission 2 → Grand Total`

Observed logic: `Total Amount = Customs + Gov Fees + Profit + VAT`, and `Grand Total = Total Amount + Commission`.

---

## 2. Technology Stack

| Layer | Choice | Reason |
|-------|--------|--------|
| Backend | **Laravel 11 (PHP 8.2+)** | Runs natively on Hostinger; strongest framework for accounting/admin. |
| Database | **MySQL 8** | Native on Hostinger; matches "SQL for backend". |
| Web UI | **Inertia.js + React + Tailwind** | Modern SPA feel without a separate API client; Framer Motion for polish. |
| Mobile API | **Laravel JSON API (Sanctum tokens)** | Same business logic reused by a future Flutter app. |
| Charts | Recharts (web) | Dashboard analytics. |
| Excel | Laravel Excel (PhpSpreadsheet) | Import `.xlsx/.xlsm/.csv`, export Excel. |
| PDF | DomPDF / Snappy | Report + invoice PDF export. |
| Auth | Laravel Breeze + Sanctum + Policies | Web session + API tokens, one authorization layer. |

**Architecture principle:** All business logic (calculations, balances, credit) lives in service classes and is exercised by both the Inertia web controllers and the JSON API controllers. Controllers stay thin. This guarantees the future Flutter app behaves identically to the web app.

---

## 3. Roles & Permissions

| Role | Capabilities |
|------|--------------|
| **Super Admin** | Everything, incl. user management, masters, settings, delete, backups. |
| **Admin** | Transactions, masters, reports; no user management / system settings. |
| **Accountant** | Create/edit transactions, record credit payments, run reports. |
| **Read-only** | View dashboards, transactions, reports. No writes. |

Enforced centrally via Laravel Policies + a middleware, applied identically to web and API routes.

---

## 4. Data Model (Phase 1)

### Core
- **transactions**: `id, transaction_date, invoice_no, boe_no, customer_id, reference_id, vehicle_id, customs_fees, gov_fees, profit, vat_rate, vat_amount, total_amount, payment_method_id, credit_amount, remarks, attachment_path, created_by, timestamps, soft_deletes`. Grand total and net profit are **derived**, not stored (or stored as cached read-model, recomputed on write).
- **transaction_expenses**: `id, transaction_id, expense_category_id, description, amount`. (Many per transaction — covers inline + multiple rows.)
- **transaction_commissions**: `id, transaction_id, label, amount, type ['charged_to_customer' | 'paid_to_reference'], reference_id (nullable)`. (Many per transaction — flexible commission.)
- **credit_payments**: `id, transaction_id, payment_date, amount, payment_method_id, note, created_by`. (Receipts against a credit sale → drives Outstanding Credit.)

### Masters (each Super-Admin editable)
- **customers**: `name, contact, notes, opening_balance`
- **references**: `name, contact` (reference/agent people)
- **vehicles**: `number, notes`
- **expense_categories**: `name` (ZAJEL, Courier, Typing, Fuel, Salik, Parking, Printing, Misc…)
- **payment_methods**: `name` (Cash, Bank, Credit, Card, Cheque, Online Transfer) + `type` for balance bucketing (cash/bank/other)
- **banks**: `name, account_no, opening_balance`
- **account_heads**: `name, type` (future-ready ledger scaffolding)

### System
- **users**: standard + `role`
- **settings**: key/value (VAT rate default `0`, company name/logo/TRN, currency `AED`)

All money columns: `DECIMAL(12,2)`. Single currency (AED) in Phase 1; multi-currency deferred.

---

## 5. Calculation Engine (single source of truth)

Implemented in one `TransactionCalculator` service, unit-tested, used everywhere:

```
VAT amount        = taxable_base × vat_rate            (rate configurable; default 0%)
Total Amount      = Customs + Gov Fees + Profit + VAT
Grand Total       = Total Amount + Σ(commissions where type = charged_to_customer)
Total Expenses    = Σ(expense rows)
Commission Payable = Σ(commissions where type = paid_to_reference)
Net Profit        = Profit − Total Expenses − Commission Payable
Credit Outstanding = credit_amount − Σ(credit_payments)
Cash Balance      = Σ opening(cash) + cash receipts − cash payments
Bank Balance      = Σ opening(bank) + bank receipts − bank payments
```

No field that can be computed is ever hand-entered. The entry form shows all totals live as the user types.

---

## 6. Screens (Phase 1)

1. **Login** + Forgot Password.
2. **Dashboard**: Today's Income, Today's Expenses, Cash Balance, Bank Balance, Credit Balance, Total Profit, Monthly Income, Monthly Expenses, Total Customers, Pending Credits, Recent Transactions. Charts: Daily Income vs Expense, Payment Method Breakdown. (Remaining charts → Phase 2.)
3. **Transaction Entry**: Basic info (date, invoice, BOE, customer, reference, vehicle) · Income (customs, gov fees, profit, VAT, total — auto) · Payment (method, credit amount, remarks, invoice/attachment upload) · **multiple expense rows** · **multiple commission rows** (with type) · live totals.
4. **Transactions list**: paginated, with search/filter by date range, invoice, BOE, customer, vehicle, reference, payment method, amount, profit.
5. **Credit / Receivables**: outstanding list + record payment against a credit sale.
6. **Masters**: CRUD pages for each master table.
7. **Import**: upload `.xlsm/.xlsx/.csv`, map columns, **dry-run preview with validation**, then commit. Bulk import of historical workbook.
8. **Reports** (Phase 1 set): **Daily, Monthly, Customer-wise, Outstanding Credit** — each with filters, totals, a chart, and **PDF / Excel / Print** export.
9. **User management** (Super Admin).

---

## 7. Import Design

- Detect per-day sheets; parse rows into the transaction + expense + commission model.
- Column-mapping step (defaults pre-filled from the known workbook layout).
- Validation pass (missing customer/date, non-numeric money) surfaced in a preview table.
- Unknown customers/references/vehicles auto-created as masters (flagged for review).
- Idempotent: re-importing the same invoice/date does not duplicate.

---

## 8. Testing & Safety

- **Unit tests** for `TransactionCalculator` (every formula above) and the importer parser — the two places a bug costs real money.
- **Feature tests** for role-based access (each role hits allowed/denied routes).
- Importer always runs dry-run preview before writing.
- Soft-deletes on transactions (recycle bin proper in Phase 3).

---

## 9. Out of Scope (later phases)

- **Phase 2:** all remaining reports (Cash Book, Bank Book, Ledger, Vehicle/Reference/Commission/Payment/Expense reports, Weekly/Yearly/Custom, Income/Profit), full dashboard chart set, notifications/alerts.
- **Phase 3:** audit log, edit history, deleted-records bin UI, backup/restore, **dynamic custom fields** (no-code form builder).
- **Phase 4:** CRM, Invoicing, VAT filing, Payroll, Employee mgmt, Document mgmt, Shipping/Customs tracking, Bank reconciliation, multi-branch, multi-currency, Flutter mobile app.

---

## 10. Success Criteria (Phase 1)

1. A user can log in by role and see a working dashboard with correct live figures.
2. A transaction matching any workbook row can be entered, with all totals auto-calculated identically to the Excel.
3. Existing historical data imports from the `.xlsm` without duplication or math errors.
4. Credit sales track outstanding balance and accept payments until cleared.
5. Daily / Monthly / Customer / Outstanding-Credit reports export to PDF and Excel.
6. All calculation and access-control tests pass.
