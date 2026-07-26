# CMV Shipping Accounts — Phase 2 Plan

**Goal:** Add the accountant's daily books, the full report set, richer dashboard analytics, and alerts — on top of the Phase 1 foundation.

**Approach:** Mostly additive. Books and new reports reuse the existing `Transaction`/`CreditPayment` data and the `TransactionCalculator`/`BalanceService`. New running-balance logic lives in a `LedgerService`; new report types extend `ReportBuilder`.

## Milestones

### P2-M1 — Books (running-balance ledgers)
- `LedgerService`: `cashBook(filters)`, `bankBook(filters)`, `customerLedger(customerId, filters)` — each returns `{opening, rows[{date, description, ref, debit, credit, balance}], closing, totals}`.
- Money flow (consistent with Phase 1 `BalanceService`):
  - Cash: opening = `cash_opening_balance`; **in** = cash-method sales' grand_total + cash credit-repayments; **out** = expenses.
  - Bank: opening = Σ bank opening balances; **in** = bank-method sales + bank credit-repayments.
  - Customer ledger: **debit** = credit_amount on credit sales; **credit** = credit payments; balance = running outstanding.
- Controller + Inertia pages + nav "Books" section. PDF/Excel/Print reuse the report exporters.

### P2-M2 — Full report set
Extend `ReportBuilder` with: weekly, yearly, custom-period, vehicle-wise, reference-wise, commission, payment-method, expense, income, profit. Register in the Reports index.

### P2-M3 — Dashboard analytics
Add charts: Monthly Profit trend (12 months), Top Customers, Expense Categories, Cash Flow (in/out per day).

### P2-M4 — Notifications
`NotificationService`: pending credits, large expenses (> configurable threshold), low cash balance (< threshold), monthly profit summary. Bell in header + dashboard alerts panel. Thresholds in settings.

## Testing
Pest coverage for `LedgerService` (running balances, opening/closing), each new report type's totals, and `NotificationService` triggers.
