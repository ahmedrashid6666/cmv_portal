<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Setting;
use App\Services\LedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookController extends Controller
{
    /**
     * Combined Cash & Bank book view. Which books show is driven by
     * ?show=cash,bank (both by default). Export still uses cashBook/bankBook.
     */
    public function cashBank(Request $request, LedgerService $ledger)
    {
        $filters = $request->only(['from', 'to']);
        $show = $request->filled('show')
            ? explode(',', $request->string('show')->value())
            : ['cash', 'bank'];

        return Inertia::render('Books/CashBank', [
            'filters' => $filters,
            'show' => $show,
            'cash' => in_array('cash', $show, true) ? $ledger->cashBook($filters) : null,
            'bank' => in_array('bank', $show, true) ? $ledger->bankBook($filters) : null,
            'cashOpening' => (float) Setting::get('cash_opening_balance', 0),
        ]);
    }

    /** Set the cash book's opening balance (the same setting as Settings). */
    public function updateCashOpening(Request $request)
    {
        $data = $request->validate(['cash_opening_balance' => ['required', 'numeric']]);
        Setting::put('cash_opening_balance', $data['cash_opening_balance']);

        return back()->with('success', 'Cash opening balance updated.');
    }

    public function cashBook(Request $request, LedgerService $ledger)
    {
        return $this->render('cash', 'Cash Book', $ledger->cashBook($request->only(['from', 'to'])), $request);
    }

    public function bankBook(Request $request, LedgerService $ledger)
    {
        return $this->render('bank', 'Bank Book', $ledger->bankBook($request->only(['from', 'to'])), $request);
    }

    public function ledger(Request $request, LedgerService $ledger)
    {
        $customerId = $request->integer('customer_id');
        $book = $customerId
            ? $ledger->customerLedger($customerId, $request->only(['from', 'to']))
            : ['opening' => 0, 'rows' => [], 'closing' => 0, 'totals' => ['debit' => 0, 'credit' => 0], 'customer' => null];

        return Inertia::render('Books/Ledger', [
            'book' => $book,
            'filters' => $request->only(['from', 'to', 'customer_id']),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function render(string $key, string $title, array $book, Request $request)
    {
        if ($request->string('format')->value()) {
            return $this->export($title, $book, $request->string('format')->value());
        }

        return Inertia::render('Books/Book', [
            'bookKey' => $key,
            'title' => $title,
            'book' => $book,
            'filters' => $request->only(['from', 'to']),
        ]);
    }

    private function export(string $title, array $book, string $format)
    {
        $columns = ['Date', 'Description', 'Ref', 'Debit (In)', 'Credit (Out)', 'Balance'];
        $rows = array_map(fn ($r) => [
            Carbon::parse($r['date'])->format('d-m-Y'), $r['description'], $r['ref'] ?? '',
            number_format($r['debit'], 2), number_format($r['credit'], 2), number_format($r['balance'], 2),
        ], $book['rows']);

        $report = [
            'type' => strtolower(str_replace(' ', '-', $title)),
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => [
                'Opening' => $book['opening'],
                'Total In' => $book['totals']['debit'],
                'Total Out' => $book['totals']['credit'],
                'Closing' => $book['closing'],
            ],
        ];

        return $format === 'pdf'
            ? Pdf::loadView('reports.pdf', ['report' => $report])->download($report['type'].'.pdf')
            : $this->xlsx($report);
    }

    private function xlsx(array $report): StreamedResponse
    {
        $ss = new Spreadsheet;
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
        $offset = 0;
        foreach ($report['totals'] as $label => $value) {
            $sheet->setCellValue([1 + $offset, $totalsRow], $label.': '.number_format($value, 2));
            $offset++;
        }

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $report['type'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
