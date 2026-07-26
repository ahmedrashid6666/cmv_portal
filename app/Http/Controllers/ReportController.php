<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\ReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index()
    {
        return Inertia::render('Reports/Index', [
            'reports' => [
                ['key' => 'daily', 'title' => 'Daily Report', 'desc' => "A single day's transactions and totals."],
                ['key' => 'monthly', 'title' => 'Monthly Report', 'desc' => 'Day-by-day income and profit for a month.'],
                ['key' => 'customer', 'title' => 'Customer-wise Report', 'desc' => 'Income and profit grouped by customer.'],
                ['key' => 'outstanding-credit', 'title' => 'Outstanding Credit', 'desc' => 'Unpaid receivables by invoice.'],
            ],
        ]);
    }

    public function show(Request $request, string $type, ReportBuilder $builder)
    {
        abort_unless(in_array($type, ReportBuilder::TYPES, true), 404);

        $filters = $request->only(['date', 'from', 'to', 'customer_id', 'year', 'month']);

        return Inertia::render('Reports/Show', [
            'report' => $builder->build($type, $filters),
            'filters' => $filters,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request, string $type, ReportBuilder $builder)
    {
        abort_unless(in_array($type, ReportBuilder::TYPES, true), 404);

        $filters = $request->only(['date', 'from', 'to', 'customer_id', 'year', 'month']);
        $report = $builder->build($type, $filters);
        $format = $request->string('format')->value();

        return $format === 'pdf'
            ? $this->pdf($report)
            : $this->xlsx($report);
    }

    private function pdf(array $report)
    {
        $pdf = Pdf::loadView('reports.pdf', ['report' => $report]);

        return $pdf->download($report['type'].'-report.pdf');
    }

    private function xlsx(array $report): StreamedResponse
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setCellValue('A1', $report['title']);

        foreach ($report['columns'] as $i => $col) {
            $sheet->setCellValue([$i + 1, 3], $col);
        }
        foreach ($report['rows'] as $r => $row) {
            foreach ($row as $c => $val) {
                $sheet->setCellValue([$c + 1, $r + 4], $val);
            }
        }
        $totalsRow = count($report['rows']) + 5;
        $sheet->setCellValue([1, $totalsRow], 'TOTALS');
        $offset = 0;
        foreach ($report['totals'] as $label => $value) {
            $sheet->setCellValue([2 + $offset, $totalsRow], $label.': '.number_format($value, 2));
            $offset++;
        }

        $filename = $report['type'].'-report.xlsx';

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
