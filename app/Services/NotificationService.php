<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionExpense;
use Illuminate\Support\Carbon;

/**
 * Derives dashboard alerts from current data + configurable thresholds:
 *   - pending credits (any outstanding receivable)
 *   - large expenses (single expense >= large_expense_threshold)
 *   - low cash balance (cash < low_cash_threshold)
 *   - monthly profit summary (this month's net profit)
 */
class NotificationService
{
    public function __construct(private BalanceService $balances) {}

    /**
     * @return array<int, array{level: string, title: string, message: string}>
     */
    public function alerts(): array
    {
        $alerts = [];

        // Pending credits
        $pending = $this->balances->pendingCreditsCount();
        if ($pending > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Pending credits',
                'message' => "{$pending} invoice(s) with outstanding balance, totalling AED "
                    .number_format((float) $this->balances->creditOutstandingTotal(), 2).'.',
            ];
        }

        // Large expenses this month
        $threshold = (float) Setting::get('large_expense_threshold', 1000);
        $large = TransactionExpense::query()
            ->where('amount', '>=', $threshold)
            ->whereHas('transaction', fn ($q) => $q
                ->whereYear('transaction_date', Carbon::today()->year)
                ->whereMonth('transaction_date', Carbon::today()->month))
            ->count();
        if ($large > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Large expenses',
                'message' => "{$large} expense(s) at or above AED ".number_format($threshold, 2).' this month.',
            ];
        }

        // Low cash balance — only once there is real activity, so a fresh
        // empty system doesn't nag about being below the threshold.
        $lowCash = (float) Setting::get('low_cash_threshold', 500);
        $cash = (float) $this->balances->cashBalance();
        $hasActivity = Transaction::query()->exists() || (float) Setting::get('cash_opening_balance', 0) != 0;
        if ($hasActivity && $cash < $lowCash) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Low cash balance',
                'message' => 'Cash balance is AED '.number_format($cash, 2).', below the AED '
                    .number_format($lowCash, 2).' threshold.',
            ];
        }

        // Monthly profit summary
        $today = Carbon::today();
        $profit = (float) Transaction::query()
            ->whereYear('transaction_date', $today->year)
            ->whereMonth('transaction_date', $today->month)
            ->sum('net_profit');
        $alerts[] = [
            'level' => $profit >= 0 ? 'info' : 'danger',
            'title' => 'Monthly profit',
            'message' => $today->format('F').' net profit so far: AED '.number_format($profit, 2).'.',
        ];

        return $alerts;
    }
}
