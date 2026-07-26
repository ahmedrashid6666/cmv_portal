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
    public const TYPES = ['daily', 'monthly', 'customer', 'outstanding-credit'];

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
