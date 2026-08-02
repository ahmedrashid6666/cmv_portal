<?php

namespace App\Services;

use App\Models\CashCount;
use App\Models\FinalCalculation;
use App\Models\LedgerEntry;
use App\Models\Setting;

/**
 * Builds and evaluates the "Final Calculation" reconciliation worksheet.
 *
 * The worksheet is a grid of rows, each mirroring the four Excel columns:
 *   amount | ac_balance | debt_exp | cash (cash_aed, reconciled; cash_omr tracked separately)
 *
 * cash_omr is displayed for reference only — it is never converted into or
 * added to the AED total, so the OMR rate plays no part in reconciliation.
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
     * Evaluate the reconciliation totals from a data payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, float>
     */
    public function compute(array $data): array
    {
        $rows = $data['rows'] ?? [];

        $sum = fn (string $col) => round(array_reduce(
            $rows,
            fn ($carry, $row) => $carry + (float) ($row[$col] ?? 0),
            0.0,
        ), 2);

        $totalAmount = $sum('amount');
        $totalAcBalance = $sum('ac_balance');
        $totalDebtExp = $sum('debt_exp');
        $liquidCash = round($totalAmount - ($totalAcBalance + $totalDebtExp), 2);

        // OMR is tracked for reference only — it is not converted or added into
        // the AED total/reconciliation.
        $cashAedTotal = $sum('cash_aed');
        $cashOmrTotal = round(array_reduce(
            $rows,
            fn ($carry, $row) => $carry + (float) ($row['cash_omr'] ?? 0),
            0.0,
        ), 3);
        $cashCounted = $cashAedTotal;

        return [
            'total_amount' => $totalAmount,
            'total_ac_balance' => $totalAcBalance,
            'total_debt_exp' => $totalDebtExp,
            'liquid_cash' => $liquidCash,
            'cash_omr_total' => $cashOmrTotal,
            'cash_counted' => $cashCounted,
            'cash_extra' => round($cashCounted - $liquidCash, 2),
        ];
    }

    /**
     * The Total Liquid Cash in CMV figure for a date — the saved snapshot's
     * total if one exists, otherwise the live-computed default. Used as the
     * "Expected Cash" baseline on the Daily Cash Count page, so both screens
     * reconcile against the same number.
     */
    public function liquidCashFor(string $date): float
    {
        $snapshot = FinalCalculation::whereDate('calc_date', $date)->first();
        $data = $snapshot ? $snapshot->data : $this->defaults($date);

        return $this->compute($data)['liquid_cash'];
    }

    /**
     * Auto-filled worksheet for a date: the live core rows, plus the manual
     * rows carried forward (labels + values) from the most recent snapshot.
     *
     * @return array<string, mixed>
     */
    public function defaults(string $date): array
    {
        $rows = $this->coreRows($date);

        foreach ($this->carriedManualRows($date) as $row) {
            $rows[] = $row;
        }

        $rows = $this->withDwsCashDefault($rows, $date);

        return [
            'rows' => $rows,
            'omr_rate' => (float) Setting::get('omr_to_aed_rate', self::DEFAULT_OMR_RATE),
            'remarks' => null,
        ];
    }

    /**
     * Overlay the Daily Work Sheet Bal row's Cash (AED)/(OMR) cells with the
     * date's actual saved Daily Cash Count, whether $data came from a live
     * default or an already-saved snapshot. Without this, saving a Final
     * Calculation before the day's cash count is entered (or re-counted)
     * freezes a stale/zero figure that a later Cash Count save never reaches.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withLiveCashCount(array $data, string $date): array
    {
        $data['rows'] = $this->withDwsCashDefault($data['rows'] ?? [], $date);

        return $data;
    }

    /**
     * The auto-computed rows every worksheet starts with.
     *
     * @return array<int, array<string, mixed>>
     */
    private function coreRows(string $date): array
    {
        $rows = [
            $this->row('dws_bal', 'DAILY WORK SHEET BAL', 'top', ['amount' => (float) $this->balances->dwsBalance($date)], 'amount'),
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

    /**
     * The Daily Work Sheet Bal row's Cash (AED)/(OMR) cells default to that
     * date's actual Daily Cash Count total when one has been saved; otherwise
     * they default to Total Liquid Cash in CMV, so the sheet reads as
     * "balanced" until the real count overrides it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function withDwsCashDefault(array $rows, string $date): array
    {
        $count = CashCount::whereDate('count_date', $date)->first();

        return array_map(function ($row) use ($rows, $count) {
            if (($row['key'] ?? null) !== 'dws_bal') {
                return $row;
            }

            if ($count) {
                $row['cash_aed'] = (float) $count->total_aed;
                $row['cash_omr'] = (float) $count->total_omr;

                return $row;
            }

            $sum = fn (string $col) => array_reduce(
                $rows,
                fn ($carry, $r) => $carry + (float) ($r[$col] ?? 0),
                0.0,
            );
            $liquidCash = $sum('amount') - ($sum('ac_balance') + $sum('debt_exp'));
            $row['cash_aed'] = round($liquidCash, 2);

            return $row;
        }, $rows);
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
