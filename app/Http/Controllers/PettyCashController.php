<?php

namespace App\Http\Controllers;

use App\Models\PettyCashEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PettyCashController extends Controller
{
    public function index()
    {
        return Inertia::render('PettyCash/Index', [
            'entries' => PettyCashEntry::latest('entry_date')->latest('id')->paginate(20)->withQueryString(),
            'totals' => [
                'in' => (float) PettyCashEntry::sum('in_amount'),
                'out' => (float) PettyCashEntry::sum('out_amount'),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $entries = PettyCashEntry::latest('entry_date')->latest('id')->get();

        $report = [
            'type' => 'petty-cash',
            'title' => 'Petty Cash Report',
            'columns' => ['Date', 'Item', 'Description', 'In', 'Out'],
            'rows' => $entries->map(fn ($e) => [
                $e->entry_date->format('d-m-Y'), $e->item, $e->description ?? '—',
                number_format((float) $e->in_amount, 2), number_format((float) $e->out_amount, 2),
            ])->all(),
            'totals' => [
                'Total In' => round((float) $entries->sum('in_amount'), 2),
                'Total Out' => round((float) $entries->sum('out_amount'), 2),
            ],
        ];

        return $request->string('format')->value() === 'pdf'
            ? Pdf::loadView('reports.pdf', ['report' => $report])->download('petty-cash-report.pdf')
            : $this->xlsx($report);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        PettyCashEntry::create($data);

        return back()->with('success', 'Petty cash entry added.');
    }

    public function update(Request $request, PettyCashEntry $pettyCashEntry)
    {
        $pettyCashEntry->update($this->validated($request));

        return back()->with('success', 'Petty cash entry updated.');
    }

    public function destroy(PettyCashEntry $pettyCashEntry)
    {
        $pettyCashEntry->delete();

        return back()->with('success', 'Petty cash entry deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'item' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'in_amount' => ['nullable', 'numeric', 'min:0'],
            'out_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        // A blank field arrives as null (ConvertEmptyStringsToNull) — the
        // columns are NOT NULL with a 0 default, so an explicit null insert
        // would violate that constraint.
        $data['in_amount'] ??= 0;
        $data['out_amount'] ??= 0;

        return $data;
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
        $offset = 0;
        foreach ($report['totals'] as $label => $value) {
            $sheet->setCellValue([1 + $offset, $totalsRow], $label.': '.number_format($value, 2));
            $offset++;
        }

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $report['type'].'-report.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
