<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * One unified "Operations" workspace. A type tab picks what to list
 * (transactions / invoices / credits / daily-credit / borrowed); every list
 * supports search + date filters, sortable columns, and the deletable/ledger
 * types support bulk delete and bulk payment/return on the selected rows.
 */
class OperationsController extends Controller
{
    private const TABS = [
        'transactions' => 'Transactions',
        'invoices' => 'Invoices',
        'credits' => 'Credits',
        'daily-credit' => 'Daily Credit',
        'borrowed' => 'Borrowed Amount',
    ];

    public function index(Request $request)
    {
        $type = array_key_exists($request->string('type')->value(), self::TABS)
            ? $request->string('type')->value()
            : 'transactions';

        $from = $request->input('from') ?: null;
        $to = $request->input('to') ?: null;
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->value();
        $sort = $request->string('sort')->value();
        $dir = $request->string('dir')->value() === 'asc' ? 'asc' : 'desc';

        $payload = match ($type) {
            'invoices' => $this->invoices($from, $to, $search, $sort, $dir),
            'credits' => $this->credits($from, $to, $search, $sort, $dir),
            'daily-credit', 'borrowed' => $this->ledger($type, $from, $to, $search, $status, $sort, $dir),
            default => $this->transactions($from, $to, $search, $sort, $dir),
        };

        return Inertia::render('Operations/Index', array_merge($payload, [
            'tabs' => collect(self::TABS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'type' => $type,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to, 'status' => $status],
            'sort' => ['by' => $sort, 'dir' => $dir],
            'isLedger' => in_array($type, ['daily-credit', 'borrowed'], true),
            'paymentMethods' => PaymentMethod::whereIn('type', ['cash', 'bank'])->orderBy('name')->get(['id', 'name']),
        ]));
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:transactions,daily-credit,borrowed'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $count = $data['type'] === 'transactions'
            ? Transaction::whereIn('id', $data['ids'])->delete()
            : LedgerEntry::ofType($data['type'] === 'daily-credit' ? 'daily_credit' : 'borrowed')
                ->whereIn('id', $data['ids'])->delete();

        return back()->with('success', "{$count} record(s) moved to the recycle bin.");
    }

    /**
     * Apply a sort from the header, or the sensible default (newest first).
     *
     * @param  array<string, mixed>  $map  sortKey => column string | closure($q,$dir)
     */
    private function sort($query, ?string $sort, string $dir, array $map, string $defaultCol): void
    {
        if ($sort && isset($map[$sort])) {
            $col = $map[$sort];
            $col instanceof \Closure ? $col($query, $dir) : $query->orderBy($col, $dir);

            return;
        }
        $query->orderBy($defaultCol, 'desc')->orderBy('id', 'desc');
    }

    private function customerSub(): \Closure
    {
        return fn ($q, $dir) => $q->orderBy(
            Customer::select('name')->whereColumn('customers.id', 'transactions.customer_id')->limit(1), $dir
        );
    }

    private function creditSettle(Transaction $t): ?array
    {
        if ((float) $t->credit_amount <= 0) {
            return null;
        }

        return [
            'kind' => 'credit',
            'id' => $t->id,
            'label' => ($t->invoice_no ?? 'TXN-'.$t->id).' — '.$t->customer?->name,
            'credit' => (float) $t->credit_amount,
            'outstanding' => round((float) $t->creditOutstanding(), 2),
            'payments' => $t->creditPayments->map(fn ($p) => [
                'id' => $p->id, 'date' => $p->payment_date->format('d/m/Y'),
                'amount' => (float) $p->amount, 'method' => $p->paymentMethod?->name ?? '—',
            ])->values(),
        ];
    }

    private function transactions(?string $from, ?string $to, string $search, ?string $sort, string $dir): array
    {
        $query = Transaction::query()
            ->with(['customer:id,name', 'paymentMethod:id,name', 'creditPayments.paymentMethod:id,name'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('boe_no', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))));

        $this->sort($query, $sort, $dir, [
            'transaction_date' => 'transaction_date', 'invoice_no' => 'invoice_no',
            'customer' => $this->customerSub(),
            'method' => fn ($q, $d) => $q->orderBy(PaymentMethod::select('name')->whereColumn('payment_methods.id', 'transactions.payment_method_id')->limit(1), $d),
            'grand_total' => 'grand_total', 'net_profit' => 'net_profit',
        ], 'transaction_date');

        $rows = $query->paginate(50)->withQueryString()->through(fn ($t) => [
            'id' => $t->id, 'status' => $t->invoiceStatus(),
            'action_url' => route('transactions.edit', $t->id), 'settle' => $this->creditSettle($t),
            'cells' => [
                $t->transaction_date->format('d/m/Y'), $t->invoice_no ?? '—', $t->customer?->name,
                $t->paymentMethod?->name, number_format((float) $t->grand_total, 2), number_format((float) $t->net_profit, 2),
            ],
        ]);

        return ['columns' => ['Date', 'Invoice', 'Customer', 'Method', 'Grand Total', 'Net Profit'], 'rows' => $rows,
            'sortKeys' => ['transaction_date', 'invoice_no', 'customer', 'method', 'grand_total', 'net_profit'],
            'statusOptions' => [], 'actionLabel' => 'Edit', 'bulkDeletable' => true];
    }

    private function invoices(?string $from, ?string $to, string $search, ?string $sort, string $dir): array
    {
        $query = Transaction::query()
            ->with(['customer:id,name'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where('invoice_no', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));

        $this->sort($query, $sort, $dir, [
            'transaction_date' => 'transaction_date', 'invoice_no' => 'invoice_no',
            'customer' => $this->customerSub(), 'grand_total' => 'grand_total',
        ], 'transaction_date');

        $rows = $query->paginate(50)->withQueryString()->through(fn ($t) => [
            'id' => $t->id, 'status' => $t->invoiceStatus(), 'action_url' => route('invoices.show', $t->id),
            'cells' => [
                $t->transaction_date->format('d/m/Y'), $t->invoice_no ?? '—', $t->customer?->name,
                number_format((float) $t->grand_total, 2), number_format((float) $t->creditOutstanding(), 2),
            ],
        ]);

        return ['columns' => ['Date', 'Invoice', 'Customer', 'Grand Total', 'Outstanding'], 'rows' => $rows,
            'sortKeys' => ['transaction_date', 'invoice_no', 'customer', 'grand_total', null],
            'statusOptions' => [], 'actionLabel' => 'View', 'bulkDeletable' => false];
    }

    private function credits(?string $from, ?string $to, string $search, ?string $sort, string $dir): array
    {
        $query = Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with(['customer:id,name', 'creditPayments.paymentMethod:id,name'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));

        $this->sort($query, $sort, $dir, [
            'transaction_date' => 'transaction_date', 'invoice_no' => 'invoice_no',
            'customer' => $this->customerSub(), 'credit_amount' => 'credit_amount',
        ], 'transaction_date');

        $rows = $query->paginate(50)->withQueryString()->through(function ($t) {
            $out = (float) $t->creditOutstanding();

            return [
                'id' => $t->id,
                'status' => $out <= 0 ? 'paid' : ($out < (float) $t->credit_amount ? 'partial' : 'unpaid'),
                'action_url' => route('credits.index'), 'settle' => $this->creditSettle($t),
                'cells' => [
                    $t->transaction_date->format('d/m/Y'), $t->invoice_no ?? '—', $t->customer?->name,
                    number_format((float) $t->credit_amount, 2), number_format($out, 2),
                ],
            ];
        });

        return ['columns' => ['Date', 'Invoice', 'Customer', 'Credit', 'Outstanding'], 'rows' => $rows,
            'sortKeys' => ['transaction_date', 'invoice_no', 'customer', 'credit_amount', null],
            'statusOptions' => [], 'actionLabel' => 'Receive', 'bulkDeletable' => false];
    }

    private function ledger(string $type, ?string $from, ?string $to, string $search, string $status, ?string $sort, string $dir): array
    {
        $modelType = $type === 'daily-credit' ? 'daily_credit' : 'borrowed';

        $query = LedgerEntry::ofType($modelType)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('party_name', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")));

        $this->sort($query, $sort, $dir, [
            'entry_date' => 'entry_date', 'party_name' => 'party_name', 'reference' => 'reference',
            'vehicle_number' => 'vehicle_number', 'total_amount' => 'total_amount',
            'paid_amount' => 'paid_amount', 'balance_amount' => 'balance_amount',
        ], 'entry_date');

        $rows = $query->paginate(50)->withQueryString()->through(fn ($e) => [
            'id' => $e->id, 'status' => $e->status, 'action_url' => route('ledger.index', $type),
            'settle' => ['kind' => 'ledger', 'slug' => $type, 'id' => $e->id, 'label' => $e->party_name, 'total' => (float) $e->total_amount, 'paid' => (float) $e->paid_amount],
            'cells' => [
                $e->entry_date->format('d/m/Y'), $e->party_name, $e->reference ?? '—', $e->vehicle_number ?? '—',
                number_format((float) $e->total_amount, 2), number_format((float) $e->paid_amount, 2), number_format((float) $e->balance_amount, 2),
            ],
        ]);

        return [
            'columns' => ['Date', $type === 'borrowed' ? 'Person' : 'Customer', 'Reference', 'Vehicle', 'Total', $type === 'borrowed' ? 'Returned' : 'Paid', 'Balance'],
            'rows' => $rows,
            'sortKeys' => ['entry_date', 'party_name', 'reference', 'vehicle_number', 'total_amount', 'paid_amount', 'balance_amount'],
            'statusOptions' => ['pending' => 'Pending', 'partial' => $type === 'borrowed' ? 'Partially Returned' : 'Partially Paid', 'returned' => 'Returned'],
            'actionLabel' => 'Edit', 'bulkDeletable' => true,
        ];
    }
}
