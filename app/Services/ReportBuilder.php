<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Builds the Phase 1 report set: daily, monthly, customer-wise and
 * outstanding-credit. Each returns a uniform shape so the same React page
 * and the same PDF/Excel exporters can render any of them.
 */
class ReportBuilder
{
    public const TYPES = [
        'daily', 'monthly', 'customer', 'outstanding-credit',
        'weekly', 'yearly', 'custom', 'vehicle', 'reference',
        'commission', 'payment-method', 'expense', 'income', 'profit',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{type: string, title: string, columns: array<int, string>, rows: array<int, array<int, string>>, totals: array<string, float>, chart: array<int, array<string, mixed>>}
     */
    public function build(string $type, array $filters): array
    {
        return match ($type) {
            'daily' => $this->daily($filters),
            'monthly' => $this->monthly($filters),
            'customer' => $this->customer($filters),
            'outstanding-credit' => $this->outstandingCredit($filters),
            'weekly', 'custom' => $this->periodList($type, $filters),
            'yearly' => $this->yearly($filters),
            'vehicle' => $this->grouped('vehicle', 'Vehicle-wise Report', 'Vehicle', fn ($t) => $t->vehicle?->number ?? '—', $filters, ['vehicle']),
            'reference' => $this->grouped('reference', 'Reference-wise Report', 'Reference', fn ($t) => $t->reference?->name ?? '—', $filters, ['reference']),
            'payment-method' => $this->grouped('payment-method', 'Payment Method Report', 'Method', fn ($t) => $t->paymentMethod?->name ?? '—', $filters, ['paymentMethod']),
            'commission' => $this->commission($filters),
            'expense' => $this->expense($filters),
            'income' => $this->incomeOrProfit('income', $filters),
            'profit' => $this->incomeOrProfit('profit', $filters),
            default => abort(404),
        };
    }

    private function baseQuery(array $filters)
    {
        return Transaction::query()
            ->with(['customer:id,name', 'paymentMethod:id,name'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '<=', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v));
    }

    private function daily(array $filters): array
    {
        $date = $filters['date'] ?? Carbon::today()->toDateString();
        $txns = $this->baseQuery(['from' => $date, 'to' => $date] + $filters)->orderBy('id')->get();

        return [
            'type' => 'daily',
            'title' => 'Daily Report — '.$date,
            'columns' => ['Invoice', 'Customer', 'Method', 'Customs', 'Profit', 'Total', 'Grand Total', 'Net Profit'],
            'rows' => $txns->map(fn ($t) => [
                $t->invoice_no ?? '—', $t->customer?->name, $t->paymentMethod?->name,
                (string) $t->customs_fees, (string) $t->profit, (string) $t->total_amount,
                (string) $t->grand_total, (string) $t->net_profit,
            ])->all(),
            'totals' => $this->totals($txns),
            'chart' => [],
        ];
    }

    private function monthly(array $filters): array
    {
        $year = (int) ($filters['year'] ?? Carbon::today()->year);
        $month = (int) ($filters['month'] ?? Carbon::today()->month);
        $txns = Transaction::query()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->get();

        $byDay = $txns->groupBy(fn ($t) => $t->transaction_date->format('Y-m-d'))
            ->map(fn ($group, $day) => [
                'day' => $day,
                'income' => (float) $group->sum('grand_total'),
                'profit' => (float) $group->sum('net_profit'),
            ])->values();

        return [
            'type' => 'monthly',
            'title' => 'Monthly Report — '.Carbon::create($year, $month)->format('F Y'),
            'columns' => ['Day', 'Transactions', 'Income', 'Net Profit'],
            'rows' => $byDay->map(fn ($d) => [
                $d['day'],
                (string) $txns->filter(fn ($t) => $t->transaction_date->format('Y-m-d') === $d['day'])->count(),
                number_format($d['income'], 2),
                number_format($d['profit'], 2),
            ])->all(),
            'totals' => $this->totals($txns),
            'chart' => $byDay->map(fn ($d) => ['label' => Carbon::parse($d['day'])->format('d'), 'income' => $d['income'], 'profit' => $d['profit']])->all(),
        ];
    }

    private function customer(array $filters): array
    {
        $txns = $this->baseQuery($filters)->get();
        $byCustomer = $txns->groupBy(fn ($t) => $t->customer?->name ?? '—')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'count' => $group->count(),
                'income' => (float) $group->sum('grand_total'),
                'profit' => (float) $group->sum('net_profit'),
            ])->sortByDesc('income')->values();

        return [
            'type' => 'customer',
            'title' => 'Customer-wise Report',
            'columns' => ['Customer', 'Transactions', 'Income', 'Net Profit'],
            'rows' => $byCustomer->map(fn ($c) => [
                $c['name'], (string) $c['count'], number_format($c['income'], 2), number_format($c['profit'], 2),
            ])->all(),
            'totals' => $this->totals($txns),
            'chart' => $byCustomer->take(8)->map(fn ($c) => ['label' => $c['name'], 'income' => $c['income']])->all(),
        ];
    }

    private function outstandingCredit(array $filters): array
    {
        $rows = [];
        $total = 0.0;
        $chart = [];
        Transaction::query()
            ->where('credit_amount', '>', 0)
            ->with(['customer:id,name', 'creditPayments'])
            ->orderBy('transaction_date')
            ->get()
            ->each(function ($t) use (&$rows, &$total) {
                $out = (float) $t->creditOutstanding();
                if ($out <= 0) {
                    return;
                }
                $total += $out;
                $rows[] = [
                    $t->transaction_date->format('Y-m-d'),
                    $t->invoice_no ?? '—',
                    $t->customer?->name,
                    number_format((float) $t->credit_amount, 2),
                    number_format($out, 2),
                ];
            });

        return [
            'type' => 'outstanding-credit',
            'title' => 'Outstanding Credit Report',
            'columns' => ['Date', 'Invoice', 'Customer', 'Credit', 'Outstanding'],
            'rows' => $rows,
            'totals' => ['Outstanding' => round($total, 2)],
            'chart' => $chart,
        ];
    }

    private function periodList(string $type, array $filters): array
    {
        $txns = $this->baseQuery($filters)->orderBy('transaction_date')->orderBy('id')->get();
        $label = $type === 'weekly' ? 'Weekly Report' : 'Custom Period Report';
        $range = ($filters['from'] ?? '…').' → '.($filters['to'] ?? '…');

        return [
            'type' => $type,
            'title' => $label.' ('.$range.')',
            'columns' => ['Date', 'Invoice', 'Customer', 'Method', 'Total', 'Grand Total', 'Net Profit'],
            'rows' => $txns->map(fn ($t) => [
                $t->transaction_date->format('Y-m-d'), $t->invoice_no ?? '—', $t->customer?->name,
                $t->paymentMethod?->name, (string) $t->total_amount, (string) $t->grand_total, (string) $t->net_profit,
            ])->all(),
            'totals' => $this->totals($txns),
            'chart' => [],
        ];
    }

    private function yearly(array $filters): array
    {
        $year = (int) ($filters['year'] ?? Carbon::today()->year);
        $txns = Transaction::query()->whereYear('transaction_date', $year)->get();
        $byMonth = collect(range(1, 12))->map(function ($m) use ($txns) {
            $group = $txns->filter(fn ($t) => (int) $t->transaction_date->format('n') === $m);

            return [
                'label' => Carbon::create(null, $m)->format('M'),
                'count' => $group->count(),
                'income' => (float) $group->sum('grand_total'),
                'profit' => (float) $group->sum('net_profit'),
            ];
        });

        return [
            'type' => 'yearly',
            'title' => 'Yearly Report — '.$year,
            'columns' => ['Month', 'Transactions', 'Income', 'Net Profit'],
            'rows' => $byMonth->map(fn ($m) => [$m['label'], (string) $m['count'], number_format($m['income'], 2), number_format($m['profit'], 2)])->all(),
            'totals' => $this->totals($txns),
            'chart' => $byMonth->map(fn ($m) => ['label' => $m['label'], 'income' => $m['income'], 'profit' => $m['profit']])->all(),
        ];
    }

    private function grouped(string $type, string $title, string $keyLabel, callable $keyFn, array $filters, array $with): array
    {
        $txns = $this->baseQuery($filters)->with($with)->get();
        $groups = $txns->groupBy($keyFn)->map(fn ($group, $name) => [
            'name' => (string) $name,
            'count' => $group->count(),
            'income' => (float) $group->sum('grand_total'),
            'profit' => (float) $group->sum('net_profit'),
        ])->sortByDesc('income')->values();

        return [
            'type' => $type,
            'title' => $title,
            'columns' => [$keyLabel, 'Transactions', 'Income', 'Net Profit'],
            'rows' => $groups->map(fn ($g) => [$g['name'], (string) $g['count'], number_format($g['income'], 2), number_format($g['profit'], 2)])->all(),
            'totals' => $this->totals($txns),
            'chart' => $groups->take(8)->map(fn ($g) => ['label' => $g['name'], 'income' => $g['income']])->all(),
        ];
    }

    private function commission(array $filters): array
    {
        $rows = \App\Models\TransactionCommission::query()
            ->with(['transaction:id,transaction_date,invoice_no,customer_id', 'transaction.customer:id,name', 'reference:id,name'])
            ->whereHas('transaction', fn ($q) => $this->applyDateRange($q, $filters))
            ->get();

        $toCustomer = (float) $rows->where('type', 'charged_to_customer')->sum('amount');
        $toReference = (float) $rows->where('type', 'paid_to_reference')->sum('amount');

        return [
            'type' => 'commission',
            'title' => 'Commission Report',
            'columns' => ['Date', 'Invoice', 'Customer', 'Label', 'Type', 'Reference', 'Amount'],
            'rows' => $rows->map(fn ($c) => [
                $c->transaction?->transaction_date?->format('Y-m-d'), $c->transaction?->invoice_no ?? '—',
                $c->transaction?->customer?->name, $c->label ?? '—',
                $c->type === 'charged_to_customer' ? 'To Customer' : 'To Reference',
                $c->reference?->name ?? '—', number_format((float) $c->amount, 2),
            ])->all(),
            'totals' => ['Charged to Customer' => round($toCustomer, 2), 'Paid to Reference' => round($toReference, 2)],
            'chart' => [],
        ];
    }

    private function expense(array $filters): array
    {
        $expenses = \App\Models\TransactionExpense::query()
            ->with('category:id,name')
            ->whereHas('transaction', fn ($q) => $this->applyDateRange($q, $filters))
            ->get();

        $byCat = $expenses->groupBy(fn ($e) => $e->category?->name ?? 'Uncategorised')
            ->map(fn ($group, $name) => ['name' => (string) $name, 'count' => $group->count(), 'total' => (float) $group->sum('amount')])
            ->sortByDesc('total')->values();

        return [
            'type' => 'expense',
            'title' => 'Expense Report',
            'columns' => ['Category', 'Count', 'Total'],
            'rows' => $byCat->map(fn ($c) => [$c['name'], (string) $c['count'], number_format($c['total'], 2)])->all(),
            'totals' => ['Total Expenses' => round((float) $expenses->sum('amount'), 2)],
            'chart' => $byCat->take(8)->map(fn ($c) => ['label' => $c['name'], 'income' => $c['total']])->all(),
        ];
    }

    private function incomeOrProfit(string $type, array $filters): array
    {
        $txns = $this->baseQuery($filters)->get();
        $metric = $type === 'income' ? 'grand_total' : 'net_profit';
        $byDay = $txns->groupBy(fn ($t) => $t->transaction_date->format('Y-m-d'))
            ->map(fn ($group, $day) => ['day' => $day, 'value' => (float) $group->sum($metric)])
            ->sortKeys()->values();

        return [
            'type' => $type,
            'title' => ($type === 'income' ? 'Income' : 'Profit').' Report',
            'columns' => ['Date', 'Transactions', ucfirst($type)],
            'rows' => $byDay->map(fn ($d) => [
                $d['day'],
                (string) $txns->filter(fn ($t) => $t->transaction_date->format('Y-m-d') === $d['day'])->count(),
                number_format($d['value'], 2),
            ])->all(),
            'totals' => [ucfirst($type) => round((float) $txns->sum($metric), 2)],
            'chart' => $byDay->map(fn ($d) => ['label' => Carbon::parse($d['day'])->format('d M'), 'income' => $d['value']])->all(),
        ];
    }

    private function applyDateRange($query, array $filters)
    {
        return $query
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '<=', $v));
    }

    /**
     * @return array<string, float>
     */
    private function totals($txns): array
    {
        return [
            'Income' => round((float) $txns->sum('grand_total'), 2),
            'Total Amount' => round((float) $txns->sum('total_amount'), 2),
            'Net Profit' => round((float) $txns->sum('net_profit'), 2),
        ];
    }
}
