<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $invoices = Transaction::query()
            ->with(['customer:id,name', 'paymentMethod:id,name'])
            ->when($request->filled('search'), fn ($q) => $q->where('invoice_no', 'like', '%'.$request->string('search')->trim().'%'))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to')))
            ->latest('transaction_date')->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($t) => [
                'id' => $t->id,
                'invoice_no' => $t->invoice_no,
                'date' => $t->transaction_date->format('Y-m-d'),
                'customer' => $t->customer?->name,
                'grand_total' => (float) $t->grand_total,
                'outstanding' => (float) $t->creditOutstanding(),
                'status' => $t->invoiceStatus(),
            ]);

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['search', 'customer_id', 'from', 'to']),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Transaction $transaction)
    {
        return Inertia::render('Invoices/Show', [
            'invoice' => $this->build($transaction),
        ]);
    }

    public function pdf(Transaction $transaction)
    {
        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $this->build($transaction)]);

        return $pdf->download('invoice-'.($transaction->invoice_no ?? $transaction->id).'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Transaction $t): array
    {
        $t->load(['customer', 'reference:id,name', 'paymentMethod:id,name', 'commissions']);

        $lines = [];
        $lines[] = ['label' => 'Customs Duty (CDR)', 'amount' => (float) $t->customs_fees];
        if ((float) $t->gov_fees > 0) {
            $lines[] = ['label' => 'Government Fees', 'amount' => (float) $t->gov_fees];
        }
        $lines[] = ['label' => 'Clearing / Service Charges', 'amount' => (float) $t->profit];
        foreach ($t->commissions->where('type', 'charged_to_customer') as $c) {
            $lines[] = ['label' => $c->label ?: 'Commission', 'amount' => (float) $c->amount];
        }

        return [
            'id' => $t->id,
            'company' => [
                'name' => Setting::get('company_name', 'CMV Shipping'),
                'address' => Setting::get('company_address', ''),
                'trn' => Setting::get('company_trn', ''),
                'phone' => Setting::get('company_phone', ''),
                'email' => Setting::get('company_email', ''),
            ],
            'footer' => Setting::get('invoice_footer', 'Thank you for your business.'),
            'currency' => $t->currency ?: 'AED',
            'invoice_no' => $t->invoice_no ?? ('TXN-'.$t->id),
            'date' => $t->transaction_date->format('Y-m-d'),
            'boe_no' => $t->boe_no,
            'vehicle' => $t->vehicle_number,
            'reference' => $t->reference?->name,
            'payment_method' => $t->paymentMethod?->name,
            'customer' => [
                'name' => $t->customer?->name,
                'contact' => $t->customer?->contact,
            ],
            'lines' => $lines,
            'subtotal' => round(array_sum(array_column($lines, 'amount')), 2),
            'vat_amount' => (float) $t->vat_amount,
            'vat_rate' => (float) $t->vat_rate,
            'total' => (float) $t->grand_total,
            'paid' => (float) $t->grand_total - (float) $t->creditOutstanding(),
            'outstanding' => (float) $t->creditOutstanding(),
            'status' => $t->invoiceStatus(),
        ];
    }
}
