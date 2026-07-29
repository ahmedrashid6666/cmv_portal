<?php

namespace App\Services;

use App\Models\FinalCalculation;
use App\Models\LedgerEntry;
use App\Models\Setting;

/**
 * Builds and evaluates the "Final Calculation" reconciliation worksheet.
 *
 * The worksheet is a grid of rows, each mirroring the four Excel columns:
 *   amount | ac_balance | debt_exp | cash (cash_aed + cash_omr)
 *
 * compute() runs the exact workbook formulas (see the design spec); defaults()
 * pre-fills the auto rows for a date from the live balances. The same math is
 * mirrored in the React page so on-screen totals match the saved snapshot.
 */
class FinalCalculationService
{
    public const DEFAULT_OMR_RATE = 9.5238;

    public function __construct(private BalanceService $balances, private BankService $banks) {}

    /**
     * Evaluate the six totals from a data payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, float>
     */
    public function compute(array $data): array
    {
        $rows = $data['rows'] ?? [];
        $rate = (float) ($data['omr_rate'] ?? self::DEFAULT_OMR_RATE);

        $sum = fn (string $col) => round(array_reduce(
            $rows,
            fn ($carry, $row) => $carry + (float) ($row[$col] ?? 0),
            0.0,
        ), 2);

        $totalAmount = $sum('amount');
        $totalAcBalance = $sum('ac_balance');
        $totalDebtExp = $sum('debt_exp');
        $liquidCash = round($totalAmount - ($totalAcBalance + $totalDebtExp), 2);

        $cashCounted = round(array_reduce(
            $rows,
            fn ($carry, $row) => $carry + (float) ($row['cash_aed'] ?? 0) + (float) ($row['cash_omr'] ?? 0) * $rate,
            0.0,
        ), 2);

        return [
            'total_amount' => $totalAmount,
            'total_ac_balance' => $totalAcBalance,
            'total_debt_exp' => $totalDebtExp,
            'liquid_cash' => $liquidCash,
            'cash_counted' => $cashCounted,
            'cash_extra' => round($cashCounted - $liquidCash, 2),
        ];
    }

    /**
     * Auto-filled worksheet for a date: the live core rows, plus the manual
     * rows carried forward (labels + values) from the most recent snapshot.
     *
     * @return array<string, mixed>
     */
    public function defaults(string $date): array
    {
        $rows = $this->coreRows();

        foreach ($this->carriedManualRows($date) as $row) {
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'omr_rate' => (float) Setting::get('omr_to_aed_rate', self::DEFAULT_OMR_RATE),
            'remarks' => null,
        ];
    }

    /**
     * The auto-computed rows every worksheet starts with.
     *
     * @return array<int, array<string, mixed>>
     */
    private function coreRows(): array
    {
        $rows = [
            $this->row('dws_bal', 'DAILY WORK SHEET BAL', 'top', ['amount' => (float) $this->balances->cashBalance()], 'amount'),
            $this->row('borrowed', 'BORROWED CASH', 'top', ['amount' => $this->ledgerOutstanding(LedgerEntry::TYPE_BORROWED)], 'amount'),
            $this->row('daily_credit', 'DAILY CREDIT TOTAL', 'top', ['debt_exp' => $this->ledgerOutstanding(LedgerEntry::TYPE_CREDIT)], 'debt_exp'),
        ];

        foreach ($this->banks->balances() as $bank) {
            $rows[] = $this->row(
                'bank_'.$bank['id'],
                strtoupper($bank['name']),
                'banks',
                ['ac_balance' => (float) $bank['balance']],
                'ac_balance',
                $bank['is_customs'] ? 'OMR' : 'AED',
            );
        }

        return $rows;
    }

    /** Manual rows from the latest saved snapshot, carried into a new date. */
    private function carriedManualRows(string $date): array
    {
        $latest = FinalCalculation::whereDate('calc_date', '<=', $date)
            ->where('calc_date', '!=', $date)
            ->orderByDesc('calc_date')
            ->first();

        if (! $latest) {
            return [];
        }

        return collect($latest->data['rows'] ?? [])
            ->filter(fn ($r) => ($r['manual'] ?? false) === true)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, string $group, array $values, ?string $autoField = null, string $currency = 'AED'): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'currency' => $currency,
            'amount' => null,
            'ac_balance' => null,
            'debt_exp' => null,
            'cash_aed' => null,
            'cash_omr' => null,
            'manual' => false,
            'auto_field' => $autoField,
        ], $values);
    }

    private function ledgerOutstanding(string $type): float
    {
        return round((float) LedgerEntry::ofType($type)
            ->where('status', '!=', 'returned')
            ->sum('balance_amount'), 2);
    }
}
