<?php

namespace App\Services;

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
 *
 * Total Income = Σ Transaction.total_amount (Customs + Gov Fees + Other Amount +
 * Profit + VAT), cumulative through the worksheet date. Customs/Gov Fees are
 * counted here as part of Total Income and then subtracted again on the next
 * line, so they net to zero; Other Amount, Profit and VAT flow through and
 * affect Total Amount for real — Other Amount must NOT also be added to
 * "Customs/Gov Fees Paid" below, or it would net to zero too and vanish from
 * the ladder entirely instead of flowing through.
 *   + Borrowed Amount - Daily Credit (Pending) = Total Balance Amount
 *   - All Bank A/C Balance - CDR A/C Balance = Total Cash Balance In Hand
 *
 * All Bank A/C Balance / CDR A/C Balance MUST be each bank's actual current
 * balance (BankService::balances() — the same figures shown on the Bank
 * Accounts page), not a raw/pre-fee balance. This isn't just cosmetic
 * consistency: any fee counted in "Customs/Gov/Other Fees Paid" above that
 * was disbursed from a specific bank is *already* reflected in that bank's
 * actual balance, so subtracting the actual balance here cancels the fee out
 * exactly once overall (paid from a bank ⇒ doesn't touch cash; paid from no
 * bank ⇒ correctly stays subtracted from cash via the row above). Using a
 * pre-fee balance here would subtract that same fee a second time, making
 * Total Cash Balance In Hand artificially — and incorrectly — too low.
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
        [$bankAcBalance, $cdrAcBalance] = $this->currentBankBalances();

        return array_merge([
            'opening_balance' => (float) Setting::get('cash_opening_balance', 0),
            'total_income' => round((float) Transaction::whereDate('transaction_date', '<=', $date)->sum('total_amount'), 2),
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
     * Every bank's actual current balance (same figures as the Bank Accounts
     * page — opening balance, manual entries, *and* customs/gov/other/office
     * fees disbursed from it), split into the CDR total and everything else.
     * See the class docblock for why the actual (not pre-fee) balance is the
     * correct figure to subtract here.
     *
     * @return array{0: float, 1: float} [allBankTotal, cdrTotal]
     */
    private function currentBankBalances(): array
    {
        $bankTotal = 0.0;
        $cdrTotal = 0.0;

        foreach ($this->banks->balances() as $bank) {
            if ($bank['is_customs']) {
                $cdrTotal += $bank['balance'];
            } else {
                $bankTotal += $bank['balance'];
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
