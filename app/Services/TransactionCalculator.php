<?php

namespace App\Services;

/**
 * Single source of truth for every money calculation in the system.
 *
 * All values are handled as decimal strings with 2-dp scale using bcmath,
 * so we never accumulate floating-point error on stored totals.
 *
 * Mirrors the CMV Shipping workbook:
 *   Total Amount = Customs + Gov Fees + Other Amount + Profit + VAT
 *   Grand Total  = Total Amount + commissions charged to the customer
 *   Net Profit   = Profit - Expenses - commissions payable to references
 */
class TransactionCalculator
{
    private const SCALE = 2;

    public const COMMISSION_TO_CUSTOMER = 'charged_to_customer';

    public const COMMISSION_TO_REFERENCE = 'paid_to_reference';

    /** VAT = taxable base × (rate% / 100). */
    public function vatAmount(string|float|int $taxableBase, string|float|int $ratePercent): string
    {
        $rateFraction = bcdiv($this->s($ratePercent), '100', 6);

        return $this->round(bcmul($this->s($taxableBase), $rateFraction, 6));
    }

    /** Total Amount = customs + gov fees + other amount + profit + vat. */
    public function totalAmount(
        string|float|int $customs,
        string|float|int $govFees,
        string|float|int $otherAmount,
        string|float|int $profit,
        string|float|int $vat,
    ): string {
        $sum = bcadd($this->s($customs), $this->s($govFees), self::SCALE);
        $sum = bcadd($sum, $this->s($otherAmount), self::SCALE);
        $sum = bcadd($sum, $this->s($profit), self::SCALE);

        return bcadd($sum, $this->s($vat), self::SCALE);
    }

    /**
     * Grand Total = total amount + Σ commissions of type charged_to_customer.
     *
     * @param  array<int, array{type?: string, amount: string|float|int}>  $commissions
     */
    public function grandTotal(string|float|int $totalAmount, array $commissions): string
    {
        $extra = $this->sumCommissions($commissions, self::COMMISSION_TO_CUSTOMER);

        return bcadd($this->s($totalAmount), $extra, self::SCALE);
    }

    /**
     * @param  array<int, array{amount: string|float|int}>  $expenses
     */
    public function totalExpenses(array $expenses): string
    {
        $sum = '0.00';
        foreach ($expenses as $e) {
            $sum = bcadd($sum, $this->s($e['amount'] ?? 0), self::SCALE);
        }

        return $sum;
    }

    /**
     * Σ commissions of type paid_to_reference (money owed out).
     *
     * @param  array<int, array{type?: string, amount: string|float|int}>  $commissions
     */
    public function commissionPayable(array $commissions): string
    {
        return $this->sumCommissions($commissions, self::COMMISSION_TO_REFERENCE);
    }

    /** Net Profit = profit - total expenses - commission payable. */
    public function netProfit(
        string|float|int $profit,
        string|float|int $totalExpenses,
        string|float|int $commissionPayable,
    ): string {
        $net = bcsub($this->s($profit), $this->s($totalExpenses), self::SCALE);

        return bcsub($net, $this->s($commissionPayable), self::SCALE);
    }

    /**
     * Credit Outstanding = credit amount - Σ payments received against it.
     *
     * @param  array<int, array{amount: string|float|int}>  $payments
     */
    public function creditOutstanding(string|float|int $creditAmount, array $payments): string
    {
        $paid = '0.00';
        foreach ($payments as $p) {
            $paid = bcadd($paid, $this->s($p['amount'] ?? 0), self::SCALE);
        }

        return bcsub($this->s($creditAmount), $paid, self::SCALE);
    }

    /**
     * @param  array<int, array{type?: string, amount: string|float|int}>  $commissions
     */
    private function sumCommissions(array $commissions, string $type): string
    {
        $sum = '0.00';
        foreach ($commissions as $c) {
            if (($c['type'] ?? self::COMMISSION_TO_CUSTOMER) === $type) {
                $sum = bcadd($sum, $this->s($c['amount'] ?? 0), self::SCALE);
            }
        }

        return $sum;
    }

    /** Normalise any numeric input to a bcmath-safe string. */
    private function s(string|float|int $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    /** Round a decimal string half-up to 2 dp (money). */
    private function round(string $value): string
    {
        return number_format(round((float) $value, self::SCALE), self::SCALE, '.', '');
    }
}
