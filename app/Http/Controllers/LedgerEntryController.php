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
    /** slug => [type, module label, party field label, paid field label, total field label]. */
    private const TYPES = [
        'daily-credit' => [LedgerEntry::TYPE_CREDIT, 'Daily Credit', 'Customer Name', 'Paid Amount', 'Credit Amount'],
        'borrowed' => [LedgerEntry::TYPE_BORROWED, 'Borrowed Amount', 'Person Name', 'Returned Amount', 'Borrowed Amount'],
    ];

    private function meta(string $slug): array
    {
        abort_unless(isset(self::TYPES[$slug]), 404);
        [$type, $label, $partyLabel, $paidLabel, $totalLabel] = self::TYPES[$slug];

        return compact('slug', 'type', 'label', 'partyLabel', 'paidLabel', 'totalLabel');
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
                ->with('details')
                ->latest('entry_date')->latest('id')->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'status', 'from', 'to']),
            'customers' => \App\Models\Customer::orderBy('name')->get(['id', 'name']),
            'references' => \App\Models\Reference::orderBy('name')->get(['id', 'name', 'company']),
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $meta = $this->meta($slug);
        $data = $this->validated($request);
        $details = $data['details'] ?? [];
        unset($data['details']);
        $data['type'] = $meta['type'];
        $data['created_by'] = $request->user()->id;

        $entry = LedgerEntry::create($data);
        $this->syncDetails($entry, $details);

        return back()->with('success', $meta['label'].' entry added.');
    }

    public function update(Request $request, string $slug, LedgerEntry $ledgerEntry)
    {
        $meta = $this->meta($slug);
        abort_unless($ledgerEntry->type === $meta['type'], 404);
        $data = $this->validated($request);
        $details = $data['details'] ?? [];
        unset($data['details']);

        $ledgerEntry->update($data);
        $ledgerEntry->details()->delete();
        $this->syncDetails($ledgerEntry, $details);

        return back()->with('success', $meta['label'].' entry updated.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function syncDetails(LedgerEntry $entry, array $details): void
    {
        foreach ($details as $detail) {
            if (($detail['amount'] ?? null) === null && ($detail['description'] ?? null) === null) {
                continue;
            }
            $entry->details()->create([
                'detail_date' => $detail['detail_date'] ?? $entry->entry_date,
                'description' => $detail['description'] ?? null,
                'amount' => $detail['amount'] ?? 0,
            ]);
        }
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
                $e->entry_date->format('d-m-Y'), $e->party_name, $e->reference ?? '—', $e->vehicle_number ?? '—',
                number_format((float) $e->total_amount, 2), number_format((float) $e->paid_amount, 2),
                number_format((float) $e->balance_amount, 2), ucfirst($e->status),
                $e->return_date?->format('d-m-Y') ?? '—',
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
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'party_name' => ['required', 'string', 'max:255'],
            'contact_numbers' => ['nullable', 'array'],
            'contact_numbers.*' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:255'],
            'vehicle_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', Rule::in(['AED', 'OMR'])],
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'return_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['pending', 'partial', 'returned'])],
            'details' => ['nullable', 'array'],
            'details.*.detail_date' => ['required_with:details', 'date'],
            'details.*.description' => ['nullable', 'string', 'max:255'],
            'details.*.amount' => ['required_with:details', 'numeric', 'min:0'],
        ]);

        $data['contact_numbers'] = $this->cleanNumbers($data['contact_numbers'] ?? []);

        // The detail rows, when present, are the source of truth for the total —
        // never trust a client-computed sum.
        if (! empty($data['details'])) {
            $data['total_amount'] = round(array_sum(array_column($data['details'], 'amount')), 2);
        }

        return $data;
    }

    /**
     * Trim entries and drop blanks; return null when nothing is left.
     *
     * @param  array<int, string|null>  $numbers
     * @return array<int, string>|null
     */
    private function cleanNumbers(array $numbers): ?array
    {
        $clean = array_values(array_filter(
            array_map(fn ($n) => trim((string) $n), $numbers),
            fn ($n) => $n !== '',
        ));

        return $clean ?: null;
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
