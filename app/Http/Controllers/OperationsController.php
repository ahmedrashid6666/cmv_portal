<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * One unified "Operations" workspace. A type tab (transactions / daily-credit /
 * borrowed) picks what to list; results default to today's date and support
 * search, status and date filters plus bulk delete.
 */
class OperationsController extends Controller
{
    private const TABS = [
        'transactions' => 'Transactions',
        'daily-credit' => 'Daily Credit',
        'borrowed' => 'Borrowed Amount',
    ];

    public function index(Request $request)
    {
        $type = array_key_exists($request->string('type')->value(), self::TABS)
            ? $request->string('type')->value()
            : 'transactions';

        // Default to the current day unless the user cleared/changed the range.
        $today = Carbon::today()->toDateString();
        $from = $request->has('from') ? $request->input('from') : $today;
        $to = $request->has('to') ? $request->input('to') : $today;
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->value();

        $payload = $type === 'transactions'
            ? $this->transactions($from, $to, $search)
            : $this->ledger($type, $from, $to, $search, $status);

        return Inertia::render('Operations/Index', array_merge($payload, [
            'tabs' => collect(self::TABS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'type' => $type,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to, 'status' => $status],
            'isLedger' => $type !== 'transactions',
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

    private function transactions(string $from, string $to, string $search): array
    {
        $rows = Transaction::query()
            ->with(['customer:id,name', 'paymentMethod:id,name'])
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
                'edit_url' => route('transactions.edit', $t->id),
                'cells' => [
                    $t->transaction_date->format('d/m/Y'),
                    $t->invoice_no ?? '—',
                    $t->customer?->name,
                    $t->paymentMethod?->name,
                    number_format((float) $t->grand_total, 2),
                    number_format((float) $t->net_profit, 2),
                ],
            ]);

        return [
            'columns' => ['Date', 'Invoice', 'Customer', 'Method', 'Grand Total', 'Net Profit'],
            'rows' => $rows,
            'statusOptions' => [],
            'summary' => null,
        ];
    }

    private function ledger(string $type, string $from, string $to, string $search, string $status): array
    {
        $modelType = $type === 'daily-credit' ? 'daily_credit' : 'borrowed';
        $slug = $type;

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
                'edit_url' => route('ledger.index', $slug),
                'cells' => [
                    $e->entry_date->format('d/m/Y'),
                    $e->party_name,
                    $e->reference ?? '—',
                    $e->vehicle_number ?? '—',
                    number_format((float) $e->total_amount, 2),
                    number_format((float) $e->paid_amount, 2),
                    number_format((float) $e->balance_amount, 2),
                ],
            ]);

        return [
            'columns' => ['Date', $type === 'borrowed' ? 'Person' : 'Customer', 'Reference', 'Vehicle', 'Total', $type === 'borrowed' ? 'Returned' : 'Paid', 'Balance'],
            'rows' => $rows,
            'statusOptions' => ['pending' => 'Pending', 'partial' => $type === 'borrowed' ? 'Partially Returned' : 'Partially Paid', 'returned' => 'Returned'],
            'summary' => null,
        ];
    }
}
