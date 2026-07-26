<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Services\TransactionWriter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::query()
            ->with(['customer:id,name', 'paymentMethod:id,name', 'vehicle:id,number', 'reference:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search')->trim()->value();
                $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$s}%")
                    ->orWhere('boe_no', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('payment_method_id'), fn ($q) => $q->where('payment_method_id', $request->integer('payment_method_id')))
            ->when($request->filled('min_amount'), fn ($q) => $q->where('grand_total', '>=', $request->input('min_amount')))
            ->when($request->filled('max_amount'), fn ($q) => $q->where('grand_total', '<=', $request->input('max_amount')));

        return Inertia::render('Transactions/Index', [
            'transactions' => $query->latest('transaction_date')->latest('id')->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'from', 'to', 'customer_id', 'payment_method_id', 'min_amount', 'max_amount']),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Transactions/Form', $this->formOptions() + [
            'transaction' => null,
        ]);
    }

    public function store(TransactionRequest $request, TransactionWriter $writer)
    {
        $data = $request->validated();
        $data['attachment_path'] = $this->storeAttachment($request);
        $data['created_by'] = $request->user()->id;
        $writer->create($data);

        return redirect()->route('transactions.index')->with('success', 'Transaction saved.');
    }

    public function edit(Transaction $transaction)
    {
        $transaction->load(['expenses', 'commissions']);

        return Inertia::render('Transactions/Form', $this->formOptions() + [
            'transaction' => $transaction,
        ]);
    }

    public function update(TransactionRequest $request, Transaction $transaction, TransactionWriter $writer)
    {
        $data = $request->validated();
        if ($path = $this->storeAttachment($request)) {
            $data['attachment_path'] = $path;
        }
        $writer->update($transaction, $data);

        return redirect()->route('transactions.index')->with('success', 'Transaction updated.');
    }

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return back()->with('success', 'Transaction deleted.');
    }

    private function storeAttachment(Request $request): ?string
    {
        if ($request->hasFile('attachment')) {
            return $request->file('attachment')->store('attachments', 'public');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'references' => Reference::orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::orderBy('number')->get(['id', 'number']),
            'paymentMethods' => PaymentMethod::orderBy('name')->get(['id', 'name']),
            'expenseCategories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'vatRate' => (float) \App\Models\Setting::get('vat_rate', 0),
            'customFields' => \App\Models\CustomField::active()->get(['key', 'label', 'type', 'options', 'required']),
        ];
    }
}
