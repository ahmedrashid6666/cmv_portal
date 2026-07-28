<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionExpense;
use Illuminate\Support\Carbon;

/**
 * Produces running-balance books: Cash Book, Bank Book and per-customer Ledger.
 * The money-flow model matches BalanceService so books reconcile with the
 * dashboard balances.
 *
 * Each book returns:
 *   ['opening'=>float, 'rows'=>[['date','description','ref','debit','credit','balance'], ...],
 *    'closing'=>float, 'totals'=>['debit'=>float,'credit'=>float]]
 */
class LedgerService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function cashBook(array $filters = []): array
    {
        $opening = (float) Setting::get('cash_opening_balance', 0);
        $events = [];

        // Cash sales (debit / money in)
        Transaction::query()
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', 'cash'))
            ->with('customer:id,name')
            ->get()
            ->each(function ($t) use (&$events) {
                $events[] = $this->event($t->transaction_date, 'Sale — '.($t->customer?->name ?? ''), $t->invoice_no, (float) $t->grand_total, 0);
            });

        // Credit repayments received in cash (debit / money in)
        $this->cashOrBankRepayments('cash')->each(function ($p) use (&$events) {
            $events[] = $this->event($p->payment_date, 'Credit repayment', $p->invoice_no, (float) $p->amount, 0);
        });

        // Expenses (credit / money out)
        TransactionExpense::query()
            ->with('transaction:id,transaction_date,invoice_no')
            ->get()
            ->each(function ($e) use (&$events) {
                if (! $e->transaction) {
                    return;
                }
                $events[] = $this->event($e->transaction->transaction_date, 'Expense — '.($e->description ?? ''), $e->transaction->invoice_no, 0, (float) $e->amount);
            });

        return $this->assemble($opening, $events, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function bankBook(array $filters = []): array
    {
        $opening = (float) (Bank::sum('opening_balance') ?: 0);
        $events = [];

        Transaction::query()
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', 'bank'))
            ->with('customer:id,name')
            ->get()
            ->each(function ($t) use (&$events) {
                $events[] = $this->event($t->transaction_date, 'Sale — '.($t->customer?->name ?? ''), $t->invoice_no, (float) $t->grand_total, 0);
            });

        $this->cashOrBankRepayments('bank')->each(function ($p) use (&$events) {
            $events[] = $this->event($p->payment_date, 'Credit repayment', $p->invoice_no, (float) $p->amount, 0);
        });

        // Customs fees (paid from the CDR bank) — money out
        if (app(BankService::class)->customsBank()) {
            Transaction::query()
                ->where('customs_fees', '>', 0)
                ->with('customer:id,name')
                ->get()
                ->each(function ($t) use (&$events) {
                    $events[] = $this->event($t->transaction_date, 'Customs fee — '.($t->customer?->name ?? ''), $t->invoice_no, 0, (float) $t->customs_fees);
                });
        }

        // Government fees assigned to a bank — money out
        Transaction::query()
            ->whereNotNull('gov_bank_id')
            ->where('gov_fees', '>', 0)
            ->with('customer:id,name')
            ->get()
            ->each(function ($t) use (&$events) {
                $events[] = $this->event($t->transaction_date, 'Government fee — '.($t->customer?->name ?? ''), $t->invoice_no, 0, (float) $t->gov_fees);
            });

        return $this->assemble($opening, $events, $filters);
    }

    /**
     * Per-customer statement: debit = amount owed on credit sales,
     * credit = payments received. Running balance = outstanding.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function customerLedger(int $customerId, array $filters = []): array
    {
        $customer = Customer::findOrFail($customerId);
        $opening = (float) $customer->opening_balance;
        $events = [];

        Transaction::query()
            ->where('customer_id', $customerId)
            ->where('credit_amount', '>', 0)
            ->get()
            ->each(function ($t) use (&$events) {
                $events[] = $this->event($t->transaction_date, 'Credit sale', $t->invoice_no, (float) $t->credit_amount, 0);
            });

        CreditPayment::query()
            ->whereHas('transaction', fn ($q) => $q->where('customer_id', $customerId))
            ->with('transaction:id,invoice_no')
            ->get()
            ->each(function ($p) use (&$events) {
                $events[] = $this->event($p->payment_date, 'Payment received', $p->transaction?->invoice_no, 0, (float) $p->amount);
            });

        return $this->assemble($opening, $events, $filters) + ['customer' => $customer->name];
    }

    private function cashOrBankRepayments(string $bucket)
    {
        return CreditPayment::query()
            ->whereHas('transaction') // exclude repayments of soft-deleted transactions
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', $bucket))
            ->with('transaction:id,invoice_no')
            ->get()
            ->map(function ($p) {
                $p->invoice_no = $p->transaction?->invoice_no;

                return $p;
            });
    }

    /**
     * @return array{date: string, description: string, ref: string|null, debit: float, credit: float}
     */
    private function event($date, string $description, ?string $ref, float $debit, float $credit): array
    {
        return [
            'date' => $date instanceof Carbon ? $date->format('Y-m-d') : (string) $date,
            'description' => $description,
            'ref' => $ref,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
        ];
    }

    /**
     * Sort events by date, apply the date-range filter, and compute the
     * running balance from the opening figure.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function assemble(float $opening, array $events, array $filters): array
    {
        usort($events, fn ($a, $b) => [$a['date'], $a['description']] <=> [$b['date'], $b['description']]);

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        // Opening carries forward everything before the "from" date.
        $balance = $opening;
        $rows = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($events as $e) {
            $inRange = (! $from || $e['date'] >= $from) && (! $to || $e['date'] <= $to);
            $balance = round($balance + $e['debit'] - $e['credit'], 2);

            if ($from && $e['date'] < $from) {
                continue; // rolled into opening for the window
            }
            if (! $inRange) {
                continue;
            }

            $totalDebit = round($totalDebit + $e['debit'], 2);
            $totalCredit = round($totalCredit + $e['credit'], 2);
            $rows[] = $e + ['balance' => $balance];
        }

        // When a "from" filter is set, recompute the window opening.
        $windowOpening = $opening;
        if ($from) {
            $windowOpening = $opening;
            foreach ($events as $e) {
                if ($e['date'] < $from) {
                    $windowOpening = round($windowOpening + $e['debit'] - $e['credit'], 2);
                }
            }
            // rebuild balances from window opening
            $b = $windowOpening;
            foreach ($rows as $i => $r) {
                $b = round($b + $r['debit'] - $r['credit'], 2);
                $rows[$i]['balance'] = $b;
            }
        }

        return [
            'opening' => round($windowOpening, 2),
            'rows' => $rows,
            'closing' => $rows ? end($rows)['balance'] : round($windowOpening, 2),
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
        ];
    }
}
