<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\BankEntry;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Per-bank accounting for the government/customs disbursement accounts.
 *
 * Money-flow (Phase 1):
 *  - Customs fees are always paid from the CDR bank (resolved by setting or the
 *    bank literally named "CDR"). Every shipment drains its customs fee there.
 *  - Government fees are paid from the bank chosen on the shipment (gov_bank_id).
 *
 * A bank's balance = opening_balance − customs paid from it − gov paid from it.
 * Sales received by bank transfer are not tied to a specific bank yet, so they
 * are reported separately as "unassigned" and never distort a bank statement.
 */
class BankService
{
    /** The bank customs fees are paid from (locked to CDR). */
    public function customsBank(): ?Bank
    {
        $id = Setting::get('customs_bank_id');
        if ($id && ($bank = Bank::find($id))) {
            return $bank;
        }

        return Bank::whereRaw('LOWER(name) = ?', ['cdr'])->first();
    }

    /**
     * Every bank with its computed current balance and the fee outflows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function balances(): array
    {
        $customsBankId = $this->customsBank()?->id;
        $totalCustoms = (float) Transaction::sum('customs_fees');

        $govByBank = Transaction::query()
            ->whereNotNull('gov_bank_id')
            ->selectRaw('gov_bank_id, SUM(gov_fees) as total')
            ->groupBy('gov_bank_id')
            ->pluck('total', 'gov_bank_id');

        // Manual in/out movements, newest first, grouped per bank for the dialog.
        $entriesByBank = BankEntry::query()
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('bank_id');

        return Bank::orderBy('name')->get()->map(function ($bank) use ($customsBankId, $totalCustoms, $govByBank, $entriesByBank) {
            $customs = $bank->id === $customsBankId ? $totalCustoms : 0.0;
            $gov = (float) ($govByBank[$bank->id] ?? 0);
            $opening = (float) $bank->opening_balance;

            $entries = ($entriesByBank[$bank->id] ?? collect())->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->entry_date->format('Y-m-d'),
                'item' => $e->item,
                'description' => $e->description,
                'direction' => $e->direction,
                'amount' => (float) $e->amount,
            ])->values();

            $totalIn = (float) $entries->where('direction', 'in')->sum('amount');
            $totalOut = (float) $entries->where('direction', 'out')->sum('amount');

            return [
                'id' => $bank->id,
                'name' => $bank->name,
                'account_no' => $bank->account_no,
                'is_customs' => $bank->id === $customsBankId,
                'opening' => round($opening, 2),
                'customs_paid' => round($customs, 2),
                'gov_paid' => round($gov, 2),
                'total_in' => round($totalIn, 2),
                'total_out' => round($totalOut, 2),
                'balance' => round($opening + $totalIn - $totalOut - $customs - $gov, 2),
                'entries' => $entries,
            ];
        })->all();
    }

    /**
     * Running statement for one bank: opening, then each fee disbursement.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function statement(Bank $bank, array $filters = []): array
    {
        $isCustoms = $this->customsBank()?->id === $bank->id;
        $events = [];

        // Customs disbursements (only for the CDR bank)
        if ($isCustoms) {
            Transaction::query()
                ->where('customs_fees', '>', 0)
                ->with('customer:id,name')
                ->get()
                ->each(function ($t) use (&$events) {
                    $events[] = $this->event($t->transaction_date, 'Customs fee — '.($t->customer?->name ?? ''), $t->invoice_no, (float) $t->customs_fees);
                });
        }

        // Government fee disbursements paid from this bank
        Transaction::query()
            ->where('gov_bank_id', $bank->id)
            ->where('gov_fees', '>', 0)
            ->with('customer:id,name')
            ->get()
            ->each(function ($t) use (&$events) {
                $events[] = $this->event($t->transaction_date, 'Government fee — '.($t->customer?->name ?? ''), $t->invoice_no, (float) $t->gov_fees);
            });

        // Manual in/out movements: an "in" is a debit (adds), an "out" a credit (subtracts).
        $bank->entries()->get()->each(function ($e) use (&$events) {
            $events[] = [
                'date' => $e->entry_date->format('Y-m-d'),
                'description' => $e->item.($e->description ? ' — '.$e->description : ''),
                'ref' => null,
                'debit' => $e->direction === 'in' ? (float) $e->amount : 0.0,
                'credit' => $e->direction === 'out' ? (float) $e->amount : 0.0,
            ];
        });

        usort($events, fn ($a, $b) => [$a['date'], $a['description']] <=> [$b['date'], $b['description']]);

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $opening = (float) $bank->opening_balance;

        // Everything before the window rolls into the opening figure.
        $windowOpening = $opening;
        foreach ($events as $e) {
            if ($from && $e['date'] < $from) {
                $windowOpening = round($windowOpening + $e['debit'] - $e['credit'], 2);
            }
        }

        $balance = $windowOpening;
        $rows = [];
        $totalIn = 0.0;
        $totalOut = 0.0;
        foreach ($events as $e) {
            if ($from && $e['date'] < $from) {
                continue;
            }
            if ($to && $e['date'] > $to) {
                continue;
            }
            $balance = round($balance + $e['debit'] - $e['credit'], 2);
            $totalIn = round($totalIn + $e['debit'], 2);
            $totalOut = round($totalOut + $e['credit'], 2);
            $rows[] = $e + ['balance' => $balance];
        }

        return [
            'bank' => ['id' => $bank->id, 'name' => $bank->name, 'account_no' => $bank->account_no],
            'opening' => round($windowOpening, 2),
            'rows' => $rows,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'closing' => round($balance, 2),
        ];
    }

    /**
     * @return array{date: string, description: string, ref: string|null, debit: float, credit: float}
     */
    private function event($date, string $description, ?string $ref, float $amount): array
    {
        return [
            'date' => $date instanceof Carbon ? $date->format('Y-m-d') : (string) $date,
            'description' => $description,
            'ref' => $ref,
            'debit' => 0.0,
            'credit' => round($amount, 2),
        ];
    }
}
