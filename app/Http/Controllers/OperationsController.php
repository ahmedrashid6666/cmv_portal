<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * One unified "Operations" workspace. A type tab picks what to list
 * (transactions / invoices / credits / daily-credit / borrowed); every list
 * supports search + date filters, and the deletable/ledger types support
 * bulk delete and bulk payment/return on the selected rows.
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

        // Default: show all dates (never empty); date filters are optional.
        $from = $request->input('from') ?: null;
        $to = $request->input('to') ?: null;
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->value();

        $payload = match ($type) {
            'invoices' => $this->invoices($from, $to, $search),
            'credits' => $this->credits($from, $to, $search),
            'daily-credit', 'borrowed' => $this->ledger($type, $from, $to, $search, $status),
            default => $this->transactions($from, $to, $search),
        };

        return Inertia::render('Operations/Index', array_merge($payload, [
            'tabs' => collect(self::TABS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'type' => $type,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to, 'status' => $status],
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

    /** Payment/collect state for a credit sale (transactions + credits tabs). */
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
                'id' => $p->id,
                'date' => $p->payment_date->format('d/m/Y'),
                'amount' => (float) $p->amount,
                'method' => $p->paymentMethod?->name ?? '—',
            ])->values(),
        ];
    }

    private function transactions(?string $from, ?string $to, string $search): array
    {
        $rows = Transaction::query()
            ->with(['customer:id,name', 'paymentMethod:id,name', 'creditPayments.paymentMethod:id,name'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('boe_no', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->latest('transaction_date')->latest('id')
            ->paginate(50)->withQueryString()
            ->through(fn ($t) => [
                'id' => $t->id,
                'status' => $t->invoiceStatus(),
                'action_url' => route('transactions.edit', $t->id),
                'settle' => $this->creditSettle($t),
                'cells' => [
                    $t->transaction_date->format('d/m/Y'), $t->invoice_no ?? '—', $t->customer?->name,
                    $t->paymentMethod?->name, number_format((float) $t->grand_total, 2), number_format((float) $t->net_profit, 2),
                ],
            ]);

        return ['columns' => ['Date', 'Invoice', 'Customer', 'Method', 'Grand Total', 'Net Profit'], 'rows' => $rows,
            'statusOptions' => [], 'actionLabel' => 'Edit', 'bulkDeletable' => true];
    }

    private function invoices(?string $from, ?string $to, string $search): array
    {
        $rows = Transaction::query()
            ->with(['customer:id,name'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where('invoice_no', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")))
            ->latest('transaction_date')->latest('id')
            ->paginate(50)->withQueryString()
            ->through(fn ($t) => [
                'id' => $t->id,
                'status' => $t->invoiceStatus(),
                'action_url' => route('invoices.show', $t->id),
                'cells' => [
                    $t->transaction_date->format('d/m/Y'), $t->invoice_no ?? '—', $t->customer?->name,
                    number_format((float) $t->grand_total, 2), number_format((float) $t->creditOutstanding(), 2),
                ],
            ]);

        return ['columns' => ['Date', 'Invoice', 'Customer', 'Grand Total', 'Outstanding'], 'rows' => $rows,
            'statusOptions' => [], 'actionLabel' => 'View', 'bulkDeletable' => false];
    }

    private function credits(?string $from, ?string $to, string $search): array
    {
        $rows = Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with(['customer:id,name', 'creditPayments.paymentMethod:id,name'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")))
            ->latest('transaction_date')->latest('id')
            ->paginate(50)->withQueryString()
            ->through(function ($t) {
                $out = (float) $t->creditOutstanding();

                return [
                    'id' => $t->id,
                    'status' => $out <= 0 ? 'paid' : ($out < (float) $t->credit_amount ? 'partial' : 'unpaid'),
                    'action_url' => route('credits.index'),
                    'settle' => $this->creditSettle($t),
                    'cells' => [
                        $t->transaction_date->format('d/m/Y'), $t->invoice_no ?? '—', $t->customer?->name,
                        number_format((float) $t->credit_amount, 2), number_format($out, 2),
                    ],
                ];
            });

        return ['columns' => ['Date', 'Invoice', 'Customer', 'Credit', 'Outstanding'], 'rows' => $rows,
            'statusOptions' => [], 'actionLabel' => 'Receive', 'bulkDeletable' => false];
    }

    private function ledger(string $type, ?string $from, ?string $to, string $search, string $status): array
    {
        $modelType = $type === 'daily-credit' ? 'daily_credit' : 'borrowed';

        $rows = LedgerEntry::ofType($modelType)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('party_name', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")))
            ->latest('entry_date')->latest('id')
            ->paginate(50)->withQueryString()
            ->through(fn ($e) => [
                'id' => $e->id,
                'status' => $e->status,
                'action_url' => route('ledger.index', $type),
                'settle' => [
                    'kind' => 'ledger',
                    'slug' => $type,
                    'id' => $e->id,
                    'label' => $e->party_name,
                    'total' => (float) $e->total_amount,
                    'paid' => (float) $e->paid_amount,
                ],
                'cells' => [
                    $e->entry_date->format('d/m/Y'), $e->party_name, $e->reference ?? '—', $e->vehicle_number ?? '—',
                    number_format((float) $e->total_amount, 2), number_format((float) $e->paid_amount, 2), number_format((float) $e->balance_amount, 2),
                ],
            ]);

        return [
            'columns' => ['Date', $type === 'borrowed' ? 'Person' : 'Customer', 'Reference', 'Vehicle', 'Total', $type === 'borrowed' ? 'Returned' : 'Paid', 'Balance'],
            'rows' => $rows,
            'statusOptions' => ['pending' => 'Pending', 'partial' => $type === 'borrowed' ? 'Partially Returned' : 'Partially Paid', 'returned' => 'Returned'],
            'actionLabel' => 'Edit',
            'bulkDeletable' => true,
        ];
    }
}
