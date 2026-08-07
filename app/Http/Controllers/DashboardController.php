<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\BalanceService;
use App\Services\FinalCalculationService;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(BalanceService $balances, NotificationService $notifications, FinalCalculationService $finalCalc)
    {
        $today = Carbon::today();

        // last 14 days income vs expense
        $daily = collect(range(13, 0))->map(function ($daysAgo) use ($balances, $today) {
            $d = $today->copy()->subDays($daysAgo);

            return [
                'date' => $d->format('d M'),
                'income' => (float) $balances->todaysIncome($d),
                'expense' => (float) $balances->todaysExpenses($d),
            ];
        })->values();

        // last 14 days net cash flow (in - out)
        $cashFlow = $daily->map(fn ($d) => [
            'date' => $d['date'],
            'net' => round($d['income'] - $d['expense'], 2),
        ]);

        $byMethod = Transaction::query()
            ->join('payment_methods', 'transactions.payment_method_id', '=', 'payment_methods.id')
            ->select('payment_methods.name', DB::raw('SUM(total_amount) as total'))
            ->groupBy('payment_methods.name')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'value' => (float) $r->total]);

        // 12-month profit trend
        $profitTrend = collect(range(11, 0))->map(function ($monthsAgo) use ($today) {
            $m = $today->copy()->subMonths($monthsAgo);
            $profit = (float) Transaction::whereYear('transaction_date', $m->year)
                ->whereMonth('transaction_date', $m->month)->sum('net_profit');

            return ['label' => $m->format('M'), 'profit' => round($profit, 2)];
        })->values();

        // top customers by income
        $topCustomers = Transaction::query()
            ->join('customers', 'transactions.customer_id', '=', 'customers.id')
            ->select('customers.name', DB::raw('SUM(total_amount) as total'))
            ->groupBy('customers.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['label' => $r->name, 'income' => (float) $r->total]);

        // expense categories
        $expenseCategories = DB::table('transaction_expenses')
            ->leftJoin('expense_categories', 'transaction_expenses.expense_category_id', '=', 'expense_categories.id')
            ->select(DB::raw("COALESCE(expense_categories.name, 'Uncategorised') as name"), DB::raw('SUM(amount) as total'))
            ->groupBy('name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'value' => (float) $r->total]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'todaysIncome' => (float) $balances->todaysIncome(),
                'todaysExpenses' => (float) $balances->todaysExpenses(),
                // Matches "Total Cash Balance In Hand" on the Final Calculation
                // page — the reconciled figure (opening balance + income − fees
                // − credit unpaid − office expenses + borrowed − daily credit
                // pending − bank balances), not the raw cash ledger balance.
                'cashBalance' => (float) $finalCalc->liquidCashFor($today->toDateString()),
                'bankBalance' => (float) $balances->bankBalance(),
                'creditBalance' => (float) $balances->creditOutstandingTotal(),
                'totalProfit' => (float) $balances->totalProfit(),
                'monthlyIncome' => (float) $balances->monthlyIncome($today->year, $today->month),
                'monthlyExpenses' => (float) $balances->monthlyExpenses($today->year, $today->month),
                'monthlyDocuments' => Transaction::whereYear('transaction_date', $today->year)
                    ->whereMonth('transaction_date', $today->month)->count(),
                'totalDocuments' => Transaction::count(),
                'totalCustomers' => $balances->totalCustomers(),
                'pendingCredits' => $balances->pendingCreditsCount(),
            ],
            'alerts' => $notifications->alerts(),
            'dailyIncomeVsExpense' => $daily,
            'cashFlow' => $cashFlow,
            'paymentBreakdown' => $byMethod,
            'profitTrend' => $profitTrend,
            'topCustomers' => $topCustomers,
            'expenseCategories' => $expenseCategories,
            'recent' => Transaction::with(['customer:id,name', 'paymentMethod:id,name'])
                ->latest('transaction_date')->latest('id')->limit(8)->get(),
        ]);
    }
}
