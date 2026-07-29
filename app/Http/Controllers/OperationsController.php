<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\OfficeExpense;
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
        'office-expenses' => 'Office Expenses',
    ];

    /** Rows per page for the list; bumped high for a full export. */
    private int $perPage = 50;

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
            'office-expenses' => $this->officeExpenses($from, $to, $search, $sort, $dir),
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

    /**
     * Export the current tab (respecting search / date / status / sort filters)
     * to Excel or PDF. Reuses the exact columns and row formatting of the list.
     */
    public function export(Request $request, string $format)
    {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

        $type = array_key_exists($request->string('type')->value(), self::TABS)
            ? $request->string('type')->value()
            : 'transactions';

        $from = $request->input('from') ?: null;
        $to = $request->input('to') ?: null;
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->value();
        $sort = $request->string('sort')->value();
        $dir = $request->string('dir')->value() === 'asc' ? 'asc' : 'desc';

        $this->perPage = 100000; // one page = the whole filtered set

        $payload = match ($type) {
            'invoices' => $this->invoices($from, $to, $search, $sort, $dir),
            'credits' => $this->credits($from, $to, $search, $sort, $dir),
            'daily-credit', 'borrowed' => $this->ledger($type, $from, $to, $search, $status, $sort, $dir),
            'office-expenses' => $this->officeExpenses($from, $to, $search, $sort, $dir),
            default => $this->transactions($from, $to, $search, $sort, $dir),
        };

        $columns = $payload['columns'];
        $align = $payload['align'] ?? [];
        $rows = collect($payload['rows']->items())->map(fn ($r) => array_values($r['cells']))->all();

        $hasTotals = false;
        if (! empty($payload['totals'])) {
            $totals = array_values($payload['totals']);
            $totals[0] = 'TOTAL';
            $rows[] = $totals;
            $hasTotals = true;
        }

        $title = self::TABS[$type];
        $range = ($from || $to) ? (($from ?: '…').' to '.($to ?: '…')) : '';
        $file = $type.'-'.now()->format('Y-m-d');

        if ($format === 'xlsx') {
            return $this->exportXlsx($title, $columns, $rows, $file);
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.table', compact('title', 'columns', 'rows', 'align', 'hasTotals', 'range'))
            ->setPaper('a4', 'landscape')
            ->download($file.'.pdf');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     */
    private function exportXlsx(string $title, array $columns, array $rows, string $file): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', 'Generated '.now()->format('d-m-Y h:i A'));
        foreach ($columns as $i => $col) {
            $sheet->setCellValue([$i + 1, 4], $col);
        }
        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $val) {
                $sheet->setCellValueExplicit([$c + 1, $r + 5], (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        foreach (range(1, max(1, count($columns))) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($ss) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
        }, $file.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:transactions,daily-credit,borrowed,office-expenses'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $count = match ($data['type']) {
            'transactions' => Transaction::whereIn('id', $data['ids'])->delete(),
            'office-expenses' => OfficeExpense::whereIn('id', $data['ids'])->delete(),
            default => LedgerEntry::ofType($data['type'] === 'daily-credit' ? 'daily_credit' : 'borrowed')
                ->whereIn('id', $data['ids'])->delete(),
        };

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
            'currency' => $t->currency ?: 'AED',
            'credit' => (float) $t->credit_amount,
            'outstanding' => round((float) $t->creditOutstanding(), 2),
            'payments' => $t->creditPayments->map(fn ($p) => [
                'id' => $p->id, 'date' => $p->payment_date->format('d-m-Y'),
                'amount' => (float) $p->amount, 'method' => $p->paymentMethod?->name ?? '—',
            ])->values(),
        ];
    }

    /**
     * Sum commissions across a set of transactions, split into Com-1 (the first
     * commission per transaction) and Com-2 (everything after it).
     *
     * @return array{0: float, 1: float}  [com1Total, com2Total]
     */
    private function splitCommissionTotals($transactionIdsQuery): array
    {
        $com1 = 0.0;
        $com2 = 0.0;

        \App\Models\TransactionCommission::query()
            ->whereIn('transaction_id', $transactionIdsQuery)
            ->orderBy('transaction_id')->orderBy('id')
            ->get(['transaction_id', 'amount'])
            ->groupBy('transaction_id')
            ->each(function ($rows) use (&$com1, &$com2) {
                $com1 += (float) $rows->first()->amount;
                $com2 += (float) $rows->slice(1)->sum('amount');
            });

        return [$com1, $com2];
    }

    private function transactions(?string $from, ?string $to, string $search, ?string $sort, string $dir): array
    {
        $query = Transaction::query()
            ->with(['customer:id,name', 'reference:id,name', 'vehicle:id,number', 'paymentMethod:id,name', 'creditPayments.paymentMethod:id,name'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('boe_no', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))));

        // Totals across the whole filtered set (not just the current page).
        $totalsSource = (clone $query)->setEagerLoads([]);
        $agg = (clone $totalsSource)->selectRaw('SUM(customs_fees) customs, SUM(gov_fees) gov, SUM(profit) profit, SUM(vat_amount) vat, SUM(total_amount) total_amount, SUM(grand_total) grand_total, SUM(credit_amount) credit_amount')->first();

        // Commissions split: the first per transaction is Com-1, the rest fold
        // into Com-2 (so Com-1 + Com-2 always reconciles with the grand total).
        [$com1Total, $com2Total] = $this->splitCommissionTotals((clone $totalsSource)->select('id'));

        $currencies = (clone $totalsSource)->distinct()->pluck('currency')->map(fn ($c) => $c ?: 'AED')->unique();
        $tPrefix = $currencies->count() === 1 ? $currencies->first().' ' : '';
        $t = fn ($v) => $tPrefix.number_format((float) $v, 2);
        $totals = ['', '', '', '', '', '', $t($agg->customs), $t($agg->gov), $t($agg->profit), $t($agg->vat), $t($agg->total_amount), $t($com1Total), $t($com2Total), $t($agg->grand_total), $t($agg->credit_amount), ''];

        $query->with(['commissions' => fn ($q) => $q->orderBy('id')]);

        $this->sort($query, $sort, $dir, [
            'transaction_date' => 'transaction_date', 'invoice_no' => 'invoice_no',
            'customer' => $this->customerSub(),
            'method' => fn ($q, $d) => $q->orderBy(PaymentMethod::select('name')->whereColumn('payment_methods.id', 'transactions.payment_method_id')->limit(1), $d),
            'grand_total' => 'grand_total', 'net_profit' => 'net_profit',
        ], 'transaction_date');

        $rows = $query->paginate($this->perPage)->withQueryString()->through(function ($t) {
            $cur = $t->currency ?: 'AED';
            $money = fn ($v) => $cur.' '.number_format((float) $v, 2);

            $com1 = (float) ($t->commissions->first()->amount ?? 0);
            $com2 = (float) $t->commissions->slice(1)->sum('amount');

            return [
                'id' => $t->id, 'status' => $t->invoiceStatus(),
                'action_url' => route('transactions.edit', $t->id), 'settle' => $this->creditSettle($t),
                'cells' => [
                    $t->transaction_date->format('d-m-Y'),
                    $t->invoice_no ?? '—',
                    $t->boe_no ?? '—',
                    $t->customer?->name,
                    $t->reference?->name ?? '—',
                    $t->vehicle?->number ?? '—',
                    $money($t->customs_fees),
                    $money($t->gov_fees),
                    $money($t->profit),
                    $money($t->vat_amount),
                    $money($t->total_amount),
                    $money($com1),
                    $money($com2),
                    $money($t->grand_total),
                    $money($t->credit_amount),
                    $t->paymentMethod?->name ?? '—',
                ],
            ];
        });

        return ['columns' => ['Date', 'Invoice No', 'Boe No', 'Customer Name', 'Reference', 'Vehicle No', 'Customs Fees (CDR)', 'Other Gov.Fees', 'Profit', 'VAT', 'Total Amount', 'Com-1', 'Com-2', 'Grand Total', 'Credit Amount', 'Method'], 'rows' => $rows,
            'sortKeys' => ['transaction_date', 'invoice_no', null, 'customer', null, null, null, null, null, null, null, null, null, 'grand_total', null, 'method'],
            'align' => [false, false, false, false, false, false, true, true, true, true, true, true, true, true, true, false],
            'totals' => $totals,
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

        $rows = $query->paginate($this->perPage)->withQueryString()->through(fn ($t) => [
            'id' => $t->id, 'status' => $t->invoiceStatus(), 'action_url' => route('invoices.show', $t->id),
            'cells' => [
                $t->transaction_date->format('d-m-Y'), $t->invoice_no ?? '—', $t->customer?->name,
                ($t->currency ?: 'AED').' '.number_format((float) $t->grand_total, 2),
                ($t->currency ?: 'AED').' '.number_format((float) $t->creditOutstanding(), 2),
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

        $rows = $query->paginate($this->perPage)->withQueryString()->through(function ($t) {
            $out = (float) $t->creditOutstanding();

            return [
                'id' => $t->id,
                'status' => $out <= 0 ? 'paid' : ($out < (float) $t->credit_amount ? 'partial' : 'unpaid'),
                'action_url' => route('credits.index'), 'settle' => $this->creditSettle($t),
                'cells' => [
                    $t->transaction_date->format('d-m-Y'), $t->invoice_no ?? '—', $t->customer?->name,
                    ($t->currency ?: 'AED').' '.number_format((float) $t->credit_amount, 2),
                    ($t->currency ?: 'AED').' '.number_format($out, 2),
                ],
            ];
        });

        return ['columns' => ['Date', 'Invoice', 'Customer', 'Credit', 'Outstanding'], 'rows' => $rows,
            'sortKeys' => ['transaction_date', 'invoice_no', 'customer', 'credit_amount', null],
            'statusOptions' => [], 'actionLabel' => 'Receive', 'bulkDeletable' => false];
    }

    private function officeExpenses(?string $from, ?string $to, string $search, ?string $sort, string $dir): array
    {
        $query = OfficeExpense::query()
            ->with(['category:id,name', 'paymentMethod:id,name'])
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', "%{$search}%")
                ->orWhere('remarks', 'like', "%{$search}%")
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$search}%"))));

        $this->sort($query, $sort, $dir, [
            'expense_date' => 'expense_date',
            'category' => fn ($q, $d) => $q->orderBy(\App\Models\ExpenseCategory::select('name')->whereColumn('expense_categories.id', 'office_expenses.expense_category_id')->limit(1), $d),
            'method' => fn ($q, $d) => $q->orderBy(PaymentMethod::select('name')->whereColumn('payment_methods.id', 'office_expenses.payment_method_id')->limit(1), $d),
            'amount' => 'amount',
        ], 'expense_date');

        $rows = $query->paginate($this->perPage)->withQueryString()->through(fn ($e) => [
            'id' => $e->id, 'status' => null, 'action_url' => null,
            'cells' => [
                $e->expense_date->format('d-m-Y'),
                $e->category?->name ?? '—',
                $e->description ?: '—',
                $e->paymentMethod?->name ?? '—',
                ($e->currency ?: 'AED').' '.number_format((float) $e->amount, 2),
            ],
        ]);

        return ['columns' => ['Date', 'Category', 'Description', 'Method', 'Amount'], 'rows' => $rows,
            'sortKeys' => ['expense_date', 'category', null, 'method', 'amount'],
            'statusOptions' => [], 'actionLabel' => null, 'bulkDeletable' => true];
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

        $rows = $query->paginate($this->perPage)->withQueryString()->through(fn ($e) => [
            'id' => $e->id, 'status' => $e->status, 'action_url' => route('ledger.index', $type),
            'settle' => ['kind' => 'ledger', 'slug' => $type, 'id' => $e->id, 'label' => $e->party_name, 'total' => (float) $e->total_amount, 'paid' => (float) $e->paid_amount, 'currency' => $e->currency ?: 'AED'],
            'cells' => [
                $e->entry_date->format('d-m-Y'), $e->party_name, $e->reference ?? '—', $e->vehicle_number ?? '—',
                ($e->currency ?: 'AED').' '.number_format((float) $e->total_amount, 2),
                ($e->currency ?: 'AED').' '.number_format((float) $e->paid_amount, 2),
                ($e->currency ?: 'AED').' '.number_format((float) $e->balance_amount, 2),
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
