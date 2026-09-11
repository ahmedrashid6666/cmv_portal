<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\CompanyBankDetail;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Transaction;
use App\Rules\RequiredBankForPaymentMethod;
use App\Support\AmountInWords;
use App\Support\Branding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CreditPaymentController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->string('search')->trim()->value();

        $outstanding = Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with(['customer:id,name', 'reference:id,name', 'creditPayments'])
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('reference', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->latest('transaction_date')
            ->get()
            ->map(function ($t) {
                $out = (float) $t->creditOutstanding();
                return [
                    'id' => $t->id,
                    'date' => $t->transaction_date->format('Y-m-d'),
                    'invoice_no' => $t->invoice_no,
                    'customer_id' => $t->customer_id,
                    'customer' => $t->customer?->name,
                    'reference' => $t->reference?->name,
                    'credit_amount' => (float) $t->credit_amount,
                    'outstanding' => $out,
                ];
            })
            ->filter(fn ($r) => $r['outstanding'] > 0)
            ->values();

        return Inertia::render('Credits/Index', [
            'outstanding' => $outstanding,
            'filters' => ['search' => $search],
            'paymentMethods' => PaymentMethod::whereIn('type', ['cash', 'bank'])->orderBy('name')->get(['id', 'name', 'type']),
            'banks' => Bank::orderBy('name')->get(['id', 'name']),
            'companyBanks' => CompanyBankDetail::orderByDesc('is_default')->orderBy('bank_name')->get(),
        ]);
    }

    /**
     * A customer's Outstanding Statement — every outstanding credit invoice,
     * with the selected company bank's payment details printed at the foot
     * so the customer knows where to send payment.
     */
    public function statement(Request $request, Customer $customer)
    {
        $request->validate([
            'bank_id' => ['nullable', 'exists:company_bank_details,id'],
        ]);

        $invoices = Transaction::query()
            ->where('customer_id', $customer->id)
            ->where('credit_amount', '>', 0)
            ->with(['reference:id,name', 'creditPayments' => fn ($q) => $q->orderBy('payment_date')])
            ->orderBy('transaction_date')
            ->get()
            ->map(fn ($t) => [
                'date' => $t->transaction_date->format('d-m-Y'),
                'invoice_no' => $t->invoice_no ?? ('TXN-'.$t->id),
                'boe_no' => $t->boe_no,
                'company' => $customer->name,
                'reference' => $t->reference?->name,
                'vehicle' => $t->vehicle_number,
                'currency' => $t->currency ?: 'AED',
                'outstanding' => round((float) $t->creditOutstanding(), 2),
            ])
            ->filter(fn ($row) => $row['outstanding'] > 0)
            ->values();

        $references = $invoices->pluck('reference')->filter()->unique()->values();
        $billTo = [
            'name' => $customer->name,
            'lines' => array_values(array_filter([
                $customer->address ? 'Address: '.$customer->address : null,
                $customer->contact ? 'Contact No: '.$customer->contact : null,
                $customer->email ? 'Email: '.$customer->email : null,
                $references->isNotEmpty() ? 'Reference: '.$references->join(', ') : null,
            ])),
        ];

        return $this->renderStatementPdf($request, $invoices, $billTo, false, null, 'outstanding-statement-'.Str::slug($customer->name).'.pdf');
    }

    /**
     * A combined Outstanding Statement for the currently filtered set of
     * credit invoices (same search/date/status filters as Operations →
     * Credits) — covering every matching customer at once, not just one.
     * Used by the "Statement" button next to the filters, as opposed to the
     * per-row one on `statement()` above, which is scoped to one customer.
     */
    public function filteredStatement(Request $request)
    {
        $request->validate([
            'bank_id' => ['nullable', 'exists:company_bank_details,id'],
        ]);

        $from = $request->input('from') ?: null;
        $to = $request->input('to') ?: null;
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->value();

        // Mirrors OperationsController::whereCreditStatus() — the Credits tab
        // judges a row purely on its own credit line, not the sale's other totals.
        $out = '(transactions.credit_amount - COALESCE((select sum(cp.amount) from credit_payments cp where cp.transaction_id = transactions.id), 0))';

        $transactions = Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with(['customer:id,name', 'reference:id,name', 'creditPayments'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('boe_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('contact_numbers', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('reference', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('paymentMethod', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->when(in_array($status, ['paid', 'partial', 'unpaid'], true), fn ($q) => match ($status) {
                'paid' => $q->whereRaw("{$out} <= 0"),
                'partial' => $q->whereRaw("{$out} > 0")->whereRaw("{$out} < transactions.credit_amount"),
                'unpaid' => $q->whereRaw("{$out} >= transactions.credit_amount"),
            })
            ->orderBy('transaction_date')
            ->get();

        $invoices = $transactions->map(fn ($t) => [
            'date' => $t->transaction_date->format('d-m-Y'),
            'invoice_no' => $t->invoice_no ?? ('TXN-'.$t->id),
            'boe_no' => $t->boe_no,
            'company' => $t->customer?->name,
            'reference' => $t->reference?->name,
            'vehicle' => $t->vehicle_number,
            'currency' => $t->currency ?: 'AED',
            'outstanding' => round((float) $t->creditOutstanding(), 2),
        ])->filter(fn ($row) => $row['outstanding'] > 0)->values();

        $customerIds = $transactions->pluck('customer_id')->unique()->filter();
        $referenceNames = $invoices->pluck('reference')->filter()->unique();

        if ($customerIds->count() === 1) {
            $customer = Customer::find($customerIds->first());
            $billTo = [
                'name' => $customer->name,
                'lines' => array_values(array_filter([
                    $customer->address ? 'Address: '.$customer->address : null,
                    $customer->contact ? 'Contact No: '.$customer->contact : null,
                    $customer->email ? 'Email: '.$customer->email : null,
                    $referenceNames->isNotEmpty() ? 'Reference: '.$referenceNames->join(', ') : null,
                ])),
            ];
        } elseif ($referenceNames->count() === 1) {
            $reference = Reference::where('name', $referenceNames->first())->first();
            $billTo = [
                'name' => $reference?->company ?: ($reference?->name ?? $referenceNames->first()),
                'lines' => array_values(array_filter([
                    $reference?->contact ? 'Contact: '.$reference->contact : null,
                ])),
            ];
        } else {
            $billTo = [
                'name' => 'All Outstanding Credit Invoices',
                'lines' => array_values(array_filter([
                    $search ? 'Filter: "'.$search.'"' : null,
                ])),
            ];
        }

        $period = ($from || $to) ? ['from' => $from, 'to' => $to] : null;

        return $this->renderStatementPdf($request, $invoices, $billTo, true, $period, 'outstanding-statement-'.Str::slug($billTo['name']).'-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $invoices
     * @param  array{name: string, lines: array<int, string>}  $billTo
     * @param  array{from: ?string, to: ?string}|null  $period
     */
    private function renderStatementPdf(Request $request, $invoices, array $billTo, bool $showCompany, ?array $period, string $filename)
    {
        $bank = CompanyBankDetail::resolveFor($request);
        $totalOutstanding = round($invoices->sum('outstanding'), 2);
        $currency = $invoices->first()['currency'] ?? 'AED';

        $pdf = Pdf::loadView('credits.statement', [
            'company' => Branding::all(),
            'logoDataUri' => Branding::logoDataUri(),
            'headerBannerDataUri' => Branding::headerBannerDataUri(),
            'letterheadDataUri' => Branding::letterheadDataUri(),
            'billTo' => $billTo,
            'showCompany' => $showCompany,
            'invoices' => $invoices,
            'totalOutstanding' => $totalOutstanding,
            'amountInWords' => AmountInWords::convert($totalOutstanding, $currency),
            'currency' => $currency,
            'bank' => $bank,
            'statementDate' => $request->date('date')?->format('d-m-Y') ?? now()->format('d-m-Y'),
            'period' => $period,
            'invoiceNo' => $request->input('invoice_no'),
            'paymentMode' => $request->input('payment_mode'),
        ]);

        return $pdf->download($filename);
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
