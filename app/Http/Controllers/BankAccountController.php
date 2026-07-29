<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\BankEntry;
use App\Services\BalanceService;
use App\Services\BankService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    /**
     * Record a manual money movement (In = deposit, Out = withdrawal) on a bank.
     * Exactly one of the In / Out amounts must be positive.
     */
    public function storeEntry(Request $request, Bank $bank)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'item' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'in' => ['nullable', 'numeric', 'min:0'],
            'out' => ['nullable', 'numeric', 'min:0'],
        ]);

        $in = (float) ($data['in'] ?? 0);
        $out = (float) ($data['out'] ?? 0);

        if (($in > 0) === ($out > 0)) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount in either In or Out — not both, and not zero.',
            ]);
        }

        $bank->entries()->create([
            'entry_date' => $data['entry_date'],
            'item' => $data['item'],
            'description' => $data['description'] ?? null,
            'direction' => $in > 0 ? 'in' : 'out',
            'amount' => $in > 0 ? $in : $out,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Bank entry added.');
    }

    public function destroyEntry(BankEntry $entry)
    {
        $entry->delete();

        return back()->with('success', 'Bank entry removed.');
    }
}
