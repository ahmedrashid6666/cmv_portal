<?php

namespace App\Http\Controllers;

use App\Models\Bank;
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

    /** The paid / partial / unpaid choices offered on the transaction-backed tabs. */
    private const INVOICE_STATUSES = ['paid' => 'Paid', 'partial' => 'Partial', 'unpaid' => 'Unpaid'];

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
            'invoices' => $this->invoices($from, $to, $search, $status, $sort, $dir),
            'credits' => $this->credits($from, $to, $search, $status, $sort, $dir),
            'daily-credit', 'borrowed' => $this->ledger($type, $from, $to, $search, $status, $sort, $dir),
            'office-expenses' => $this->officeExpenses($from, $to, $search, $sort, $dir),
            default => $this->transactions($from, $to, $search, $status, $sort, $dir),
        };

        return Inertia::render('Operations/Index', array_merge($payload, [
            'tabs' => collect(self::TABS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'type' => $type,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to, 'status' => $status],
            'sort' => ['by' => $sort, 'dir' => $dir],
            'isLedger' => in_array($type, ['daily-credit', 'borrowed'], true),
            'paymentMethods' => PaymentMethod::whereIn('type', ['cash', 'bank'])->orderBy('name')->get(['id', 'name', 'type']),
            'banks' => Bank::orderBy('name')->get(['id', 'name']),
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
            'invoices' => $this->invoices($from, $to, $search, $status, $sort, $dir),
            'credits' => $this->credits($from, $to, $search, $status, $sort, $dir),
            'daily-credit', 'borrowed' => $this->ledger($type, $from, $to, $search, $status, $sort, $dir),
            'office-expenses' => $this->officeExpenses($from, $to, $search, $sort, $dir),
            default => $this->transactions($from, $to, $search, $status, $sort, $dir),
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

    /** Join an entry's contact numbers into a single display cell. */
    private function contactCell($model): string
    {
        $numbers = array_filter((array) ($model->contact_numbers ?? []));

        return $numbers ? implode(', ', $numbers) : '—';
    }

    private function customerSub(): \Closure
    {
        return fn ($q, $dir) => $q->orderBy(
            Customer::select('name')->whereColumn('customers.id', 'transactions.customer_id')->limit(1), $dir
        );
    }

    /**
     * SQL for a transaction's outstanding credit (credit_amount minus every
     * credit payment made against it) — the same figure creditOutstanding()
     * computes in PHP, rebuilt here so a status filter can run in the database
     * and so the totals row keeps covering the whole filtered set, not a page.
     */
    private function outstandingSql(): string
    {
        return '(transactions.credit_amount - COALESCE((select sum(cp.amount) from credit_payments cp where cp.transaction_id = transactions.id), 0))';
    }

    /**
     * Narrow a transactions query to one invoice status. Mirrors
     * Transaction::invoiceStatus(): anything with nothing left to collect is
     * paid, anything part-received (up front or through a later repayment) is
     * partial, the rest is unpaid.
     */
    private function whereInvoiceStatus($query, string $status): void
    {
        $out = $this->outstandingSql();

        match ($status) {
            'paid' => $query->where(fn ($q) => $q->where('credit_amount', '<=', 0)->orWhereRaw("{$out} <= 0")),
            'partial' => $query->where('credit_amount', '>', 0)->whereRaw("{$out} > 0")
                ->where(fn ($q) => $q->whereRaw('transactions.grand_total - transactions.credit_amount > 0')
                    ->orWhereRaw("{$out} < transactions.credit_amount")),
            'unpaid' => $query->where('credit_amount', '>', 0)->whereRaw("{$out} > 0")
                ->whereRaw('transactions.grand_total - transactions.credit_amount <= 0')
                ->whereRaw("{$out} >= transactions.credit_amount"),
            default => null,
        };
    }

    /**
     * The Credits tab judges a row purely on its credit line (the tab already
     * excludes rows without one), so "partial" there means part of the credit
     * itself was repaid — not that part of the sale was received up front.
     */
    private function whereCreditStatus($query, string $status): void
    {
        $out = $this->outstandingSql();

        match ($status) {
            'paid' => $query->whereRaw("{$out} <= 0"),
            'partial' => $query->whereRaw("{$out} > 0")->whereRaw("{$out} < transactions.credit_amount"),
            'unpaid' => $query->whereRaw("{$out} >= transactions.credit_amount"),
            default => null,
        };
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
                'note' => $p->note ?: null,
                'bank_missing' => $p->paymentMethod?->type === 'bank' && ! $p->bank_id,
            ])->values(),
        ];
    }

    /**
     * When the credit was last received against. Blank until something has
     * actually been collected; for a part-paid credit it is the most recent
     * repayment, and for a settled one that is the date it was cleared.
     */
    private function creditPaidDate(Transaction $t): string
    {
        $last = $t->creditPayments->max('payment_date');

        return $last ? $last->format('d-m-Y') : '—';
    }

    /**
     * A "Com-1" commission is one whose label is Com-1 (or blank — the primary
     * slot). Everything else (Com-2, Com-3, …) belongs in the Com-2 column, so a
     * shipment whose only commission sat in the sheet's Com-2 column stays there.
     */
    private function isCom1(?string $label): bool
    {
        $n = strtolower(trim((string) $label));

        return $n === '' || $n === 'com-1' || $n === 'com1';
    }

    /**
     * A money formatter for a totals row: single currency if every row in the
     * filtered set shares one, otherwise AED (mirrors the per-row display).
     */
    private function moneyFormatter($query): \Closure
    {
        $currencies = (clone $query)->distinct()->pluck('currency')->map(fn ($c) => $c ?: 'AED')->unique();
        $currency = $currencies->count() === 1 ? $currencies->first() : 'AED';

        return fn ($v) => \App\Support\Money::display($v ?? 0, $currency);
    }

    /**
     * Outstanding credit summed across a filtered set of transactions:
     * SUM(credit_amount) minus every credit payment made against them.
     */
    private function creditOutstandingTotal($transactionsQuery): float
    {
        $credit = (float) (clone $transactionsQuery)->sum('credit_amount');
        $paid = (float) \App\Models\CreditPayment::whereIn('transaction_id', (clone $transactionsQuery)->select('id'))->sum('amount');

        return $credit - $paid;
    }

    /**
     * Sum commissions across a set of transactions, split into Com-1 and Com-2
     * by their label (not their order).
     *
     * @return array{0: float, 1: float}  [com1Total, com2Total]
     */
    private function splitCommissionTotals($transactionIdsQuery): array
    {
        $com1 = 0.0;
        $com2 = 0.0;

        \App\Models\TransactionCommission::query()
            ->whereIn('transaction_id', $transactionIdsQuery)
            ->get(['label', 'amount'])
            ->each(function ($c) use (&$com1, &$com2) {
                $this->isCom1($c->label) ? $com1 += (float) $c->amount : $com2 += (float) $c->amount;
            });

        return [$com1, $com2];
    }

    private function transactions(?string $from, ?string $to, string $search, string $status, ?string $sort, string $dir): array
    {
        $query = Transaction::query()
            ->with(['customer:id,name', 'reference:id,name', 'paymentMethod:id,name,type', 'creditPayments.paymentMethod:id,name,type'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('boe_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('contact_numbers', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('reference', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('paymentMethod', fn ($c) => $c->where('name', 'like', "%{$search}%"))));

        if (in_array($status, array_keys(self::INVOICE_STATUSES), true)) {
            $this->whereInvoiceStatus($query, $status);
        }

        // Totals across the whole filtered set (not just the current page).
        $totalsSource = (clone $query)->setEagerLoads([]);
        $agg = (clone $totalsSource)->selectRaw('SUM(customs_fees) customs, SUM(gov_fees) gov, SUM(other_amount) other_amount, SUM(profit) profit, SUM(vat_amount) vat, SUM(total_amount) total_amount, SUM(grand_total) grand_total, SUM(credit_amount) credit_amount')->first();

        // Commissions split: the first per transaction is Com-1, the rest fold
        // into Com-2 (so Com-1 + Com-2 always reconciles with the grand total).
        [$com1Total, $com2Total] = $this->splitCommissionTotals((clone $totalsSource)->select('id'));

        $currencies = (clone $totalsSource)->distinct()->pluck('currency')->map(fn ($c) => $c ?: 'AED')->unique();
        $tCur = $currencies->count() === 1 ? $currencies->first() : 'AED';
        $t = fn ($v) => \App\Support\Money::display($v, $tCur);
        $totals = ['', '', '', '', '', '', '', $t($agg->customs), $t($agg->gov), $t($agg->other_amount), $t($agg->profit), $t($agg->vat), $t($agg->total_amount), $t($com1Total), $t($com2Total), $t($agg->grand_total), $t($agg->credit_amount), ''];

        $query->with(['commissions' => fn ($q) => $q->orderBy('id')]);

        $this->sort($query, $sort, $dir, [
            'transaction_date' => 'transaction_date', 'invoice_no' => 'invoice_no',
            'customer' => $this->customerSub(),
            'method' => fn ($q, $d) => $q->orderBy(PaymentMethod::select('name')->whereColumn('payment_methods.id', 'transactions.payment_method_id')->limit(1), $d),
            'grand_total' => 'grand_total', 'net_profit' => 'net_profit',
        ], 'transaction_date');

        $rows = $query->paginate($this->perPage)->withQueryString()->through(function ($t) {
            $cur = $t->currency ?: 'AED';
            $money = fn ($v) => \App\Support\Money::display($v, $cur);

            $com1 = (float) $t->commissions->filter(fn ($c) => $this->isCom1($c->label))->sum('amount');
            $com2 = (float) $t->commissions->sum('amount') - $com1;

            return [
                'id' => $t->id, 'status' => $t->invoiceStatus(),
                'action_url' => route('transactions.edit', $t->id), 'settle' => $this->creditSettle($t),
                'bank_missing' => $t->paymentMethod?->type === 'bank' && ! $t->bank_id,
                'cells' => [
                    $t->transaction_date->format('d-m-Y'),
                    $t->invoice_no ?? '—',
                    $t->boe_no ?? '—',
                    $t->customer?->name,
                    $this->contactCell($t),
                    $t->reference?->name ?? '—',
                    $t->vehicle_number ?? '—',
                    $money($t->customs_fees),
                    $money($t->gov_fees),
                    $money($t->other_amount),
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

        return ['columns' => ['Date', 'Invoice No', 'Boe No', 'Customer Name', 'Contact', 'Reference', 'Vehicle No', 'Customs Fees (CDR)', 'Gov.Fees', 'Other Amount', 'Profit', 'VAT', 'Total Amount', 'Com-1', 'Com-2', 'Grand Total', 'Credit Amount', 'Method'], 'rows' => $rows,
            'sortKeys' => ['transaction_date', 'invoice_no', null, 'customer', null, null, null, null, null, null, null, null, null, null, null, 'grand_total', null, 'method'],
            'align' => [false, false, false, false, false, false, false, true, true, true, true, true, true, true, true, true, true, false],
            'totals' => $totals,
            'statusOptions' => self::INVOICE_STATUSES, 'actionLabel' => 'Edit', 'bulkDeletable' => true, 'bulkPayable' => true];
    }

    private function invoices(?string $from, ?string $to, string $search, string $status, ?string $sort, string $dir): array
    {
        $query = Transaction::query()
            ->with(['customer:id,name'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('boe_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('contact_numbers', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('reference', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('paymentMethod', fn ($c) => $c->where('name', 'like', "%{$search}%"))));

        if (in_array($status, array_keys(self::INVOICE_STATUSES), true)) {
            $this->whereInvoiceStatus($query, $status);
        }

        // Totals across the whole filtered set (not just the current page).
        $totalsSource = (clone $query)->setEagerLoads([]);
        $agg = (clone $totalsSource)->selectRaw('SUM(grand_total) grand_total')->first();
        $t = $this->moneyFormatter($totalsSource);
        $totals = ['', '', '', '', $t($agg->grand_total), $t($this->creditOutstandingTotal($totalsSource))];

        $this->sort($query, $sort, $dir, [
            'transaction_date' => 'transaction_date', 'invoice_no' => 'invoice_no',
            'customer' => $this->customerSub(), 'grand_total' => 'grand_total',
        ], 'transaction_date');

        $rows = $query->paginate($this->perPage)->withQueryString()->through(fn ($t) => [
            'id' => $t->id, 'status' => $t->invoiceStatus(), 'action_url' => route('invoices.show', $t->id),
            'cells' => [
                $t->transaction_date->format('d-m-Y'), $t->invoice_no ?? '—', $t->customer?->name,
                $this->contactCell($t),
                \App\Support\Money::display($t->grand_total, $t->currency),
                \App\Support\Money::display($t->creditOutstanding(), $t->currency),
            ],
        ]);

        return ['columns' => ['Date', 'Invoice', 'Customer', 'Contact', 'Grand Total', 'Outstanding'], 'rows' => $rows,
            'sortKeys' => ['transaction_date', 'invoice_no', 'customer', null, 'grand_total', null],
            'align' => [false, false, false, false, true, true],
            'totals' => $totals,
            'statusOptions' => self::INVOICE_STATUSES, 'actionLabel' => 'View', 'bulkDeletable' => false];
    }

    private function credits(?string $from, ?string $to, string $search, string $status, ?string $sort, string $dir): array
    {
        $query = Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with(['customer:id,name', 'reference:id,name', 'creditPayments.paymentMethod:id,name,type'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('boe_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('contact_numbers', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('reference', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('paymentMethod', fn ($c) => $c->where('name', 'like', "%{$search}%"))));

        if (in_array($status, array_keys(self::INVOICE_STATUSES), true)) {
            $this->whereCreditStatus($query, $status);
        }

        // Totals across the whole filtered set (not just the current page).
        $totalsSource = (clone $query)->setEagerLoads([]);
        $t = $this->moneyFormatter($totalsSource);
        $totals = ['', '', '', '', '', '', '', $t((clone $totalsSource)->sum('credit_amount')), $t($this->creditOutstandingTotal($totalsSource)), ''];

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
                'bank_missing' => $t->creditPayments->contains(fn ($p) => $p->paymentMethod?->type === 'bank' && ! $p->bank_id),
                'cells' => [
                    $t->transaction_date->format('d-m-Y'), $t->invoice_no ?? '—', $t->boe_no ?? '—', $t->customer?->name, $this->contactCell($t), $t->reference?->name ?? '—', $t->vehicle_number ?? '—',
                    \App\Support\Money::display($t->credit_amount, $t->currency),
                    \App\Support\Money::display($out, $t->currency),
                    $this->creditPaidDate($t),
                ],
            ];
        });

        return ['columns' => ['Date', 'Invoice', 'Boe No', 'Customer', 'Contact', 'Reference', 'Vehicle No', 'Credit', 'Outstanding', 'Paid Date'], 'rows' => $rows,
            'sortKeys' => ['transaction_date', 'invoice_no', null, 'customer', null, null, null, 'credit_amount', null, null],
            'align' => [false, false, false, false, false, false, false, true, true, false],
            'totals' => $totals,
            'statusOptions' => self::INVOICE_STATUSES, 'actionLabel' => 'Receive', 'bulkDeletable' => false, 'bulkPayable' => true];
    }

    private function officeExpenses(?string $from, ?string $to, string $search, ?string $sort, string $dir): array
    {
        $query = OfficeExpense::query()
            ->with(['category:id,name', 'paymentMethod:id,name,type'])
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', "%{$search}%")
                ->orWhere('remarks', 'like', "%{$search}%")
                ->orWhere('contact_numbers', 'like', "%{$search}%")
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('paymentMethod', fn ($c) => $c->where('name', 'like', "%{$search}%"))));

        // Totals across the whole filtered set (not just the current page).
        $totalsSource = (clone $query)->setEagerLoads([]);
        $t = $this->moneyFormatter($totalsSource);
        $totals = ['', '', '', '', '', $t((clone $totalsSource)->sum('amount'))];

        $this->sort($query, $sort, $dir, [
            'expense_date' => 'expense_date',
            'category' => fn ($q, $d) => $q->orderBy(\App\Models\ExpenseCategory::select('name')->whereColumn('expense_categories.id', 'office_expenses.expense_category_id')->limit(1), $d),
            'method' => fn ($q, $d) => $q->orderBy(PaymentMethod::select('name')->whereColumn('payment_methods.id', 'office_expenses.payment_method_id')->limit(1), $d),
            'amount' => 'amount',
        ], 'expense_date');

        $rows = $query->paginate($this->perPage)->withQueryString()->through(fn ($e) => [
            'id' => $e->id, 'status' => null, 'action_url' => route('office-expenses.edit', $e->id),
            'bank_missing' => $e->paymentMethod?->type === 'bank' && ! $e->bank_id,
            'cells' => [
                $e->expense_date->format('d-m-Y'),
                $e->category?->name ?? '—',
                $e->description ?: '—',
                $this->contactCell($e),
                $e->paymentMethod?->name ?? '—',
                \App\Support\Money::display($e->amount, $e->currency),
            ],
        ]);

        return ['columns' => ['Date', 'Category', 'Description', 'Contact', 'Method', 'Amount'], 'rows' => $rows,
            'sortKeys' => ['expense_date', 'category', null, null, 'method', 'amount'],
            'align' => [false, false, false, false, false, true],
            'totals' => $totals,
            'statusOptions' => [], 'actionLabel' => 'Edit', 'bulkDeletable' => true];
    }

    private function ledger(string $type, ?string $from, ?string $to, string $search, string $status, ?string $sort, string $dir): array
    {
        $modelType = $type === 'daily-credit' ? 'daily_credit' : 'borrowed';

        $query = LedgerEntry::ofType($modelType)
            ->with('payments.paymentMethod:id,name,type')
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('party_name', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('contact_numbers', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhereHas('payments.paymentMethod', fn ($c) => $c->where('name', 'like', "%{$search}%"))));

        // Totals across the whole filtered set (not just the current page).
        $totalsSource = (clone $query)->setEagerLoads([]);
        $agg = (clone $totalsSource)->selectRaw('SUM(total_amount) total_amount, SUM(paid_amount) paid_amount, SUM(balance_amount) balance_amount')->first();
        $t = $this->moneyFormatter($totalsSource);
        $totals = ['', '', '', '', '', $t($agg->total_amount), $t($agg->paid_amount), $t($agg->balance_amount)];

        $this->sort($query, $sort, $dir, [
            'entry_date' => 'entry_date', 'party_name' => 'party_name', 'reference' => 'reference',
            'vehicle_number' => 'vehicle_number', 'total_amount' => 'total_amount',
            'paid_amount' => 'paid_amount', 'balance_amount' => 'balance_amount',
        ], 'entry_date');

        $rows = $query->paginate($this->perPage)->withQueryString()->through(fn ($e) => [
            'id' => $e->id, 'status' => $e->status, 'action_url' => route('ledger.index', $type),
            'settle' => [
                'kind' => 'ledger', 'slug' => $type, 'id' => $e->id, 'label' => $e->party_name,
                'total' => (float) $e->total_amount, 'paid' => (float) $e->paid_amount, 'currency' => $e->currency ?: 'AED',
                // Payment history, so a note recorded here (or through the bulk
                // dialog) is readable afterwards instead of being write-only.
                'payments' => $e->payments->sortBy([['payment_date', 'asc'], ['id', 'asc']])->map(fn ($p) => [
                    'id' => $p->id, 'date' => $p->payment_date->format('d-m-Y'),
                    'amount' => (float) $p->amount, 'method' => $p->paymentMethod?->name ?? '—',
                    'note' => $p->note ?: null,
                    'bank_missing' => $p->paymentMethod?->type === 'bank' && ! $p->bank_id,
                ])->values(),
            ],
            'bank_missing' => $e->payments->contains(fn ($p) => $p->paymentMethod?->type === 'bank' && ! $p->bank_id),
            'cells' => [
                $e->entry_date->format('d-m-Y'), $e->party_name, $this->contactCell($e), $e->reference ?? '—', $e->vehicle_number ?? '—',
                \App\Support\Money::display($e->total_amount, $e->currency),
                \App\Support\Money::display($e->paid_amount, $e->currency),
                \App\Support\Money::display($e->balance_amount, $e->currency),
            ],
        ]);

        return [
            'columns' => ['Date', $type === 'borrowed' ? 'Person' : 'Customer', 'Contact', 'Reference', 'Vehicle', 'Total', $type === 'borrowed' ? 'Returned' : 'Paid', 'Balance'],
            'rows' => $rows,
            'sortKeys' => ['entry_date', 'party_name', null, 'reference', 'vehicle_number', 'total_amount', 'paid_amount', 'balance_amount'],
            'align' => [false, false, false, false, false, true, true, true],
            'totals' => $totals,
            'statusOptions' => ['pending' => 'Pending', 'partial' => $type === 'borrowed' ? 'Partially Returned' : 'Partially Paid', 'returned' => 'Returned'],
            'actionLabel' => 'Edit', 'bulkDeletable' => true,
        ];
    }
}
