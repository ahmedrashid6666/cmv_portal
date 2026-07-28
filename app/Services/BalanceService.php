<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\Customer;
use App\Models\OfficeExpense;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionExpense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Derives cash / bank / credit balances and dashboard figures from the
 * persisted transactions. Money-flow model (Phase 1):
 *
 *  - A sale's grand_total lands in cash or bank according to its payment
 *    method's `type` (cash|bank). Credit sales land nowhere yet — they
 *    become receivables tracked by credit_amount.
 *  - Later credit repayments land in cash or bank by their own method type.
 *  - Transaction expenses are petty-cash outflows: they reduce the cash balance.
 *  - Office expenses are standalone outflows: they reduce cash or bank by the
 *    bucket of their own payment method.
 */
class BalanceService
{
    public function __construct(private TransactionCalculator $calc) {}

    public function cashBalance(): string
    {
        $opening = (string) Setting::get('cash_opening_balance', '0');
        $receipts = $this->saleReceiptsByBucket('cash');
        $repayments = $this->creditRepaymentsByBucket('cash');
        $expenses = $this->totalExpensesAll();

        $balance = bcadd($this->d($opening), $receipts, 2);
        $balance = bcadd($balance, $repayments, 2);
        $balance = bcsub($balance, $expenses, 2);

        return bcsub($balance, $this->officeExpensesByBucket('cash'), 2);
    }

    public function bankBalance(): string
    {
        $opening = (string) (Bank::sum('opening_balance') ?: '0');
        $receipts = $this->saleReceiptsByBucket('bank');
        $repayments = $this->creditRepaymentsByBucket('bank');

        $balance = bcadd($this->d($opening), $receipts, 2);
        $balance = bcadd($balance, $repayments, 2);

        return bcsub($balance, $this->officeExpensesByBucket('bank'), 2);
    }

    /** Total unpaid receivables across all credit sales. */
    public function creditOutstandingTotal(): string
    {
        $total = '0.00';
        Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with('creditPayments:id,transaction_id,amount')
            ->chunk(500, function ($chunk) use (&$total) {
                foreach ($chunk as $t) {
                    $out = $t->creditOutstanding();
                    if (bccomp($out, '0', 2) === 1) {
                        $total = bcadd($total, $out, 2);
                    }
                }
            });

        return $total;
    }

    public function pendingCreditsCount(): int
    {
        $count = 0;
        Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with('creditPayments:id,transaction_id,amount')
            ->chunk(500, function ($chunk) use (&$count) {
                foreach ($chunk as $t) {
                    if (bccomp($t->creditOutstanding(), '0', 2) === 1) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function todaysIncome(?Carbon $date = null): string
    {
        $date ??= Carbon::today();

        return $this->d((string) Transaction::whereDate('transaction_date', $date)->sum('grand_total'));
    }

    public function todaysExpenses(?Carbon $date = null): string
    {
        $date ??= Carbon::today();

        $txn = (string) TransactionExpense::whereHas('transaction', fn ($q) => $q->whereDate('transaction_date', $date))->sum('amount');
        $office = (string) OfficeExpense::whereDate('expense_date', $date)->sum('amount');

        return $this->d(bcadd($this->d($txn), $this->d($office), 2));
    }

    public function monthlyIncome(int $year, int $month): string
    {
        return $this->d((string) Transaction::whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('grand_total'));
    }

    public function monthlyExpenses(int $year, int $month): string
    {
        $txn = (string) TransactionExpense::whereHas('transaction', fn ($q) => $q
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month))
            ->sum('amount');
        $office = (string) OfficeExpense::whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)->sum('amount');

        return $this->d(bcadd($this->d($txn), $this->d($office), 2));
    }

    public function totalProfit(): string
    {
        return $this->d((string) Transaction::sum('net_profit'));
    }

    public function totalCustomers(): int
    {
        return Customer::count();
    }

    private function saleReceiptsByBucket(string $bucket): string
    {
        return $this->d((string) Transaction::query()
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', $bucket))
            ->sum('grand_total'));
    }

    private function creditRepaymentsByBucket(string $bucket): string
    {
        // Only count repayments whose parent transaction is not soft-deleted.
        return $this->d((string) DB::table('credit_payments')
            ->join('payment_methods', 'credit_payments.payment_method_id', '=', 'payment_methods.id')
            ->join('transactions', 'credit_payments.transaction_id', '=', 'transactions.id')
            ->whereNull('transactions.deleted_at')
            ->where('payment_methods.type', $bucket)
            ->sum('credit_payments.amount'));
    }

    private function totalExpensesAll(): string
    {
        // whereHas respects the Transaction soft-delete scope, so expenses of
        // deleted transactions are excluded.
        return $this->d((string) TransactionExpense::whereHas('transaction')->sum('amount'));
    }

    /** Standalone office expenses paid from the given bucket (cash|bank). */
    private function officeExpensesByBucket(string $bucket): string
    {
        return $this->d((string) OfficeExpense::query()
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', $bucket))
            ->sum('amount'));
    }

    private function d(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
