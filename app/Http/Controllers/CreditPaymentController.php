<?php

namespace App\Http\Controllers;

use App\Models\CreditPayment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Http\Request;
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
            'paymentMethods' => PaymentMethod::whereIn('type', ['cash', 'bank'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'exists:transactions,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
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
}
