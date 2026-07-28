<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Services\BalanceService;
use App\Services\BankService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankAccountController extends Controller
{
    public function index(BankService $banks, BalanceService $balances)
    {
        $rows = $banks->balances();

        return Inertia::render('Banks/Accounts', [
            'banks' => $rows,
            'totals' => [
                'opening' => round(array_sum(array_column($rows, 'opening')), 2),
                'customs_paid' => round(array_sum(array_column($rows, 'customs_paid')), 2),
                'gov_paid' => round(array_sum(array_column($rows, 'gov_paid')), 2),
                'balance' => round(array_sum(array_column($rows, 'balance')), 2),
            ],
            // The dashboard bank figure also includes bank sales / repayments that
            // aren't tied to a specific account — surface the gap transparently.
            'combinedBankBalance' => (float) $balances->bankBalance(),
        ]);
    }

    public function statement(Request $request, Bank $bank, BankService $banks)
    {
        return Inertia::render('Banks/Statement', [
            'statement' => $banks->statement($bank, $request->only(['from', 'to'])),
            'filters' => $request->only(['from', 'to']),
        ]);
    }
}
