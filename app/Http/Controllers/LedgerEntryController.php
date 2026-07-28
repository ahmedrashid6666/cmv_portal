<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LedgerEntryController extends Controller
{
    /** slug => [type, module label, party field label, paid field label]. */
    private const TYPES = [
        'daily-credit' => [LedgerEntry::TYPE_CREDIT, 'Daily Credit', 'Customer Name', 'Paid Amount'],
        'borrowed' => [LedgerEntry::TYPE_BORROWED, 'Borrowed Amount', 'Person Name', 'Returned Amount'],
    ];

    private function meta(string $slug): array
    {
        abort_unless(isset(self::TYPES[$slug]), 404);
        [$type, $label, $partyLabel, $paidLabel] = self::TYPES[$slug];

        return compact('slug', 'type', 'label', 'partyLabel', 'paidLabel');
    }

    private function filtered(Request $request, string $type)
    {
        return LedgerEntry::ofType($type)
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search')->trim();
                $q->where(fn ($w) => $w->where('party_name', 'like', "%{$s}%")
                    ->orWhere('reference', 'like', "%{$s}%")
                    ->orWhere('vehicle_number', 'like', "%{$s}%"));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('entry_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('entry_date', '<=', $request->date('to')));
    }

    public function index(Request $request, string $slug)
    {
        $meta = $this->meta($slug);
        $type = $meta['type'];

        $summary = [
            'total' => (float) LedgerEntry::ofType($type)->sum('total_amount'),
            'pending' => (float) LedgerEntry::ofType($type)->where('status', 'pending')->sum('balance_amount'),
            'partial' => (float) LedgerEntry::ofType($type)->where('status', 'partial')->sum('balance_amount'),
            'returned' => (float) LedgerEntry::ofType($type)->where('status', 'returned')->sum('total_amount'),
            'outstanding' => (float) LedgerEntry::ofType($type)->where('status', '!=', 'returned')->sum('balance_amount'),
        ];

        return Inertia::render('Ledger/Index', [
            'meta' => $meta,
            'summary' => $summary,
            'entries' => $this->filtered($request, $type)
                ->latest('entry_date')->latest('id')->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'status', 'from', 'to']),
            'customers' => \App\Models\Customer::orderBy('name')->get(['id', 'name']),
            'references' => \App\Models\Reference::orderBy('name')->get(['id', 'name']),
            'vehicles' => \App\Models\Vehicle::orderBy('number')->get(['id', 'number']),
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $meta = $this->meta($slug);
        $data = $this->validated($request);
        $data['type'] = $meta['type'];
        $data['created_by'] = $request->user()->id;
        LedgerEntry::create($data);

        return back()->with('success', $meta['label'].' entry added.');
    }

    public function update(Request $request, string $slug, LedgerEntry $ledgerEntry)
    {
        $meta = $this->meta($slug);
        abort_unless($ledgerEntry->type === $meta['type'], 404);
        $ledgerEntry->update($this->validated($request));

        return back()->with('success', $meta['label'].' entry updated.');
    }

    public function destroy(string $slug, LedgerEntry $ledgerEntry)
    {
        $meta = $this->meta($slug);
        abort_unless($ledgerEntry->type === $meta['type'], 404);
        $ledgerEntry->delete();

        return back()->with('success', $meta['label'].' entry deleted.');
    }

    /**
     * Quick-settle: set the paid/returned amount directly (from the status dialog).
     * The model recomputes balance, status and return date.
     */
    public function settle(Request $request, string $slug, LedgerEntry $ledgerEntry)
    {
        $meta = $this->meta($slug);
        abort_unless($ledgerEntry->type === $meta['type'], 404);
        $data = $request->validate(['paid_amount' => ['required', 'numeric', 'min:0']]);
        $ledgerEntry->update(['paid_amount' => $data['paid_amount']]);

        return back()->with('success', 'Paid amount updated.');
    }

    public function export(Request $request, string $slug)
    {
        $meta = $this->meta($slug);
        $entries = $this->filtered($request, $meta['type'])->latest('entry_date')->get();

        $report = [
            'type' => $slug,
            'title' => $meta['label'].' Report',
            'columns' => ['Date', $meta['partyLabel'], 'Reference', 'Vehicle', 'Total', $meta['paidLabel'], 'Balance', 'Status', 'Return Date'],
            'rows' => $entries->map(fn ($e) => [
                $e->entry_date->format('d/m/Y'), $e->party_name, $e->reference ?? '—', $e->vehicle_number ?? '—',
                number_format((float) $e->total_amount, 2), number_format((float) $e->paid_amount, 2),
                number_format((float) $e->balance_amount, 2), ucfirst($e->status),
                $e->return_date?->format('d/m/Y') ?? '—',
            ])->all(),
            'totals' => [
                'Total' => round((float) $entries->sum('total_amount'), 2),
                'Paid/Returned' => round((float) $entries->sum('paid_amount'), 2),
                'Outstanding' => round((float) $entries->where('status', '!=', 'returned')->sum('balance_amount'), 2),
            ],
        ];

        return $request->string('format')->value() === 'pdf'
            ? Pdf::loadView('reports.pdf', ['report' => $report])->download($slug.'-report.pdf')
            : $this->xlsx($report);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'entry_date' => ['required', 'date'],
            'party_name' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'vehicle_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'return_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['pending', 'partial', 'returned'])],
        ]);
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
