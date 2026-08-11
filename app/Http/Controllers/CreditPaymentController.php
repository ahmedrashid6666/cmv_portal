<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\CreditPayment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Rules\RequiredBankForPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CreditPaymentController extends Controller
{
    public function index()
    {
        $outstanding = Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with(['customer:id,name', 'creditPayments'])
            ->latest('transaction_date')
            ->get()
            ->map(function ($t) {
                $out = (float) $t->creditOutstanding();
                return [
                    'id' => $t->id,
                    'date' => $t->transaction_date->format('Y-m-d'),
                    'invoice_no' => $t->invoice_no,
                    'customer' => $t->customer?->name,
                    'credit_amount' => (float) $t->credit_amount,
                    'outstanding' => $out,
                ];
            })
            ->filter(fn ($r) => $r['outstanding'] > 0)
            ->values();

        return Inertia::render('Credits/Index', [
            'outstanding' => $outstanding,
            'paymentMethods' => PaymentMethod::whereIn('type', ['cash', 'bank'])->orderBy('name')->get(['id', 'name', 'type']),
            'banks' => Bank::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'exists:transactions,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'bank_id' => ['nullable', 'exists:banks,id', new RequiredBankForPaymentMethod($request->input('payment_method_id'))],
            'note' => ['nullable', 'string'],
        ]);

        $transaction = Transaction::findOrFail($data['transaction_id']);
        $outstanding = (float) $transaction->creditOutstanding();

        if ($data['amount'] > $outstanding + 0.001) {
            return back()->withErrors(['amount' => "Amount exceeds outstanding balance of {$outstanding}."]);
        }

        CreditPayment::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Payment recorded.');
    }

    /**
     * Settle many outstanding credit invoices at once — same FIFO/manual
     * allocation UX as Bulk Payment/Return for Daily Credit & Borrowed
     * Amount (BulkPaymentController::store), but against Transaction /
     * CreditPayment instead of LedgerEntry / LedgerPayment.
     */
    public function bulkStore(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['fifo', 'manual'])],
            'payment_date' => ['required', 'date'],
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'bank_id' => ['nullable', 'exists:banks,id', new RequiredBankForPaymentMethod($request->input('payment_method_id'))],
            'note' => ['nullable', 'string', 'max:255'],
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer'],
            'amount' => ['nullable', 'numeric', 'min:0'],                 // FIFO total
            'allocations' => ['nullable', 'array'],                        // manual: {id: amount}
        ]);

        // Selected transactions, oldest first, that still have outstanding credit.
        $transactions = Transaction::query()
            ->where('credit_amount', '>', 0)
            ->whereIn('id', $data['transaction_ids'])
            ->with('creditPayments:id,transaction_id,amount')
            ->orderBy('transaction_date')->orderBy('id')
            ->get()
            ->filter(fn ($t) => (float) $t->creditOutstanding() > 0)
            ->values();

        if ($transactions->isEmpty()) {
            return back()->withErrors(['bulk' => 'No outstanding invoices were selected.']);
        }

        // Build per-invoice allocation amounts.
        $alloc = [];
        if ($data['mode'] === 'fifo') {
            $remaining = round((float) ($data['amount'] ?? 0), 2);
            if ($remaining <= 0) {
                return back()->withErrors(['amount' => 'Enter an amount to distribute.']);
            }
            foreach ($transactions as $t) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, (float) $t->creditOutstanding());
                if ($take > 0) {
                    $alloc[$t->id] = round($take, 2);
                    $remaining = round($remaining - $take, 2);
                }
            }
            $leftover = $remaining;
        } else {
            foreach ($transactions as $t) {
                $amt = round((float) ($data['allocations'][$t->id] ?? 0), 2);
                if ($amt <= 0) {
                    continue;
                }
                $outstanding = (float) $t->creditOutstanding();
                if ($amt > $outstanding + 0.001) {
                    return back()->withErrors(['bulk' => "Allocation for '{$t->invoice_no}' exceeds its outstanding balance."]);
                }
                $alloc[$t->id] = $amt;
            }
            $leftover = 0;
        }

        if (empty($alloc)) {
            return back()->withErrors(['bulk' => 'Nothing to allocate.']);
        }

        $applied = 0;
        $count = 0;
        DB::transaction(function () use ($transactions, $alloc, $data, $request, &$applied, &$count) {
            foreach ($transactions as $t) {
                $amt = $alloc[$t->id] ?? 0;
                if ($amt <= 0) {
                    continue;
                }
                CreditPayment::create([
                    'transaction_id' => $t->id,
                    'payment_date' => $data['payment_date'],
                    'amount' => $amt,
                    'payment_method_id' => $data['payment_method_id'],
                    'bank_id' => $data['bank_id'] ?? null,
                    'note' => $data['note'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
                $applied = round($applied + $amt, 2);
                $count++;
            }
        });

        $msg = number_format($applied, 2)." applied across {$count} invoice(s).";
        if (! empty($leftover) && $leftover > 0) {
            $msg .= ' AED '.number_format($leftover, 2).' left unallocated (exceeded outstanding balance).';
        }

        // back() so this works from both the standalone Credits page and the Operations tab
        return back()->with('success', 'Bulk Payment: AED '.$msg);
    }

    /** Reverse a recorded credit payment (edits the effective paid amount). */
    public function destroyPayment(CreditPayment $creditPayment)
    {
        $creditPayment->delete();

        return back()->with('success', 'Payment reversed.');
    }
}
