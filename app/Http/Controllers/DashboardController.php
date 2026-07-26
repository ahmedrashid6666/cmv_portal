<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\BalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(BalanceService $balances)
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

        $byMethod = Transaction::query()
            ->join('payment_methods', 'transactions.payment_method_id', '=', 'payment_methods.id')
            ->select('payment_methods.name', DB::raw('SUM(grand_total) as total'))
            ->groupBy('payment_methods.name')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'value' => (float) $r->total]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'todaysIncome' => (float) $balances->todaysIncome(),
                'todaysExpenses' => (float) $balances->todaysExpenses(),
                'cashBalance' => (float) $balances->cashBalance(),
                'bankBalance' => (float) $balances->bankBalance(),
                'creditBalance' => (float) $balances->creditOutstandingTotal(),
                'totalProfit' => (float) $balances->totalProfit(),
                'monthlyIncome' => (float) $balances->monthlyIncome($today->year, $today->month),
                'monthlyExpenses' => (float) $balances->monthlyExpenses($today->year, $today->month),
                'totalCustomers' => $balances->totalCustomers(),
                'pendingCredits' => $balances->pendingCreditsCount(),
            ],
            'dailyIncomeVsExpense' => $daily,
            'paymentBreakdown' => $byMethod,
            'recent' => Transaction::with(['customer:id,name', 'paymentMethod:id,name'])
                ->latest('transaction_date')->latest('id')->limit(8)->get(),
        ]);
    }
}
