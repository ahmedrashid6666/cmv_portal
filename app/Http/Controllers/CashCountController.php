<?php

namespace App\Http\Controllers;

use App\Models\CashCount;
use App\Services\FinalCalculationService;
use App\Support\Branding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashCountController extends Controller
{
    public function index(Request $request, FinalCalculationService $finalCalc)
    {
        $date = $request->date('date')?->toDateString() ?? Carbon::today()->toDateString();
        $count = CashCount::whereDate('count_date', $date)->first();

        return Inertia::render('CashCount/Index', [
            'date' => $date,
            'denominations' => CashCount::DENOMINATIONS,
            'count' => $count ? [
                'lines' => $count->lines,
                'extras' => $count->extras ?? ['AED' => [], 'OMR' => []],
                'bundles' => $count->bundles ?? ['AED' => [], 'OMR' => []],
                'remarks' => $count->remarks,
            ] : null,
            'expectedAed' => $finalCalc->liquidCashFor($date),
            'omrRate' => $finalCalc->omrRate(),
            'history' => CashCount::latest('count_date')->limit(15)->get(['id', 'count_date', 'total_aed', 'total_omr', 'extras'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'date' => $c->count_date->format('Y-m-d'),
                    'total_aed' => (float) $c->total_aed,
                    'total_omr' => (float) $c->total_omr,
                    'balance_aed' => CashCount::extrasBalanceFor('AED', $c->extras ?? []),
                    'balance_omr' => CashCount::extrasBalanceFor('OMR', $c->extras ?? []),
                ]),
        ]);
    }

    public function store(Request $request, FinalCalculationService $finalCalc)
    {
        $data = $request->validate([
            'count_date' => ['required', 'date'],
            'lines' => ['required', 'array'],
            'extras' => ['nullable', 'array'],
            'bundles' => ['nullable', 'array'],
            'remarks' => ['nullable', 'string'],
        ]);

        $lines = $data['lines'];
        $extras = $data['extras'] ?? ['AED' => [], 'OMR' => []];
        $bundles = $data['bundles'] ?? ['AED' => [], 'OMR' => []];

        CashCount::updateOrCreate(
            ['count_date' => $data['count_date']],
            [
                'lines' => $lines,
                'extras' => $extras,
                'bundles' => $bundles,
                'total_aed' => CashCount::totalFor('AED', $lines, $extras, $bundles),
                'total_omr' => CashCount::totalFor('OMR', $lines, $extras, $bundles),
                'expected_aed' => $finalCalc->liquidCashFor($data['count_date']),
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Cash count saved for '.$data['count_date'].'.');
    }

    public function destroy(CashCount $cashCount)
    {
        $date = $cashCount->count_date->format('Y-m-d');
        $cashCount->delete();

        return back()->with('success', 'Cash count for '.$date.' deleted.');
    }

    public function pdf(CashCount $cashCount)
    {
        return Pdf::loadView('cash-count.pdf', [
            'count' => $cashCount,
        ])->download('cash-count-'.$cashCount->count_date->format('Y-m-d').'.pdf');
    }

    /**
     * The same slip as the PDF, as a workbook: one sheet per currency holding
     * the denomination count, the bundles, and the IN/OUT slip with its
     * totals, then the reconciliation figures.
     */
    public function xlsx(CashCount $cashCount): StreamedResponse
    {
        $ss = new Spreadsheet;

        foreach (['AED', 'OMR'] as $i => $cur) {
            $sheet = $i === 0 ? $ss->getActiveSheet() : $ss->createSheet();
            $sheet->setTitle($cur);
            $this->writeCurrencySheet($sheet, $cashCount, $cur);
        }

        $ss->setActiveSheetIndex(0);
        $file = 'cash-count-'.$cashCount->count_date->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function writeCurrencySheet($sheet, CashCount $count, string $cur): void
    {
        $decimals = $cur === 'OMR' ? 3 : 2;
        $row = 1;
        $sheet->setCellValue([1, $row++], Branding::name());
        $sheet->setCellValue([1, $row++], 'Daily Cash Slip — '.$count->count_date->format('d-m-Y'));
        $row++;

        // Denominations
        $sheet->fromArray([$cur.' Denom.', 'Qty', 'Amount'], null, 'A'.$row++, true);
        foreach (CashCount::DENOMINATIONS[$cur] as $denom) {
            $qty = (float) ($count->lines[$cur][(string) $denom] ?? 0);
            $sheet->fromArray([$denom, $qty ?: null, $qty ? round($denom * $qty, $decimals) : null], null, 'A'.$row++, true);
        }
        $sheet->fromArray(['Denomination Total', null, CashCount::totalFor($cur, $count->lines ?? [], [], [])], null, 'A'.$row++, true);
        $row++;

        // Bundles
        if (! empty($count->bundles[$cur])) {
            $sheet->fromArray([$cur.' Bundles', 'Amount'], null, 'A'.$row++, true);
            foreach ($count->bundles[$cur] as $b) {
                $sheet->fromArray([$b['label'] ?? '', round((float) ($b['amount'] ?? 0), 2)], null, 'A'.$row++, true);
            }
            $row++;
        }

        // IN / OUT slip, laid out like the screen: IN on the left, OUT on the right.
        $extras = array_values($count->extras[$cur] ?? []);
        $totals = CashCount::extrasTotalsFor($cur, $count->extras ?? []);
        $sheet->fromArray(['IN', null, 'OUT', null], null, 'A'.$row++, true);
        $sheet->fromArray(['Details', 'Amount', 'Details', 'Amount'], null, 'A'.$row++, true);
        $slipRows = max((int) ceil(count($extras) / 2), 1);
        for ($r = 0; $r < $slipRows; $r++) {
            $in = $extras[$r * 2] ?? null;
            $out = $extras[$r * 2 + 1] ?? null;
            $amount = fn ($x) => $x && (float) ($x['amount'] ?? 0) !== 0.0 ? round((float) $x['amount'], 2) : null;
            $sheet->fromArray([$in['label'] ?? '', $amount($in), $out['label'] ?? '', $amount($out)], null, 'A'.$row++, true);
        }
        $sheet->fromArray(['Total', $totals['in'], 'Total', $totals['out']], null, 'A'.$row++, true);
        $sheet->fromArray(['Balance Amount', $totals['balance']], null, 'A'.$row++, true);
        $row++;

        // Reconciliation (identical on both sheets — it is the day's summary).
        $difference = round((float) $count->total_aed - (float) $count->expected_aed, 2);
        $sheet->fromArray(['Counted (AED)', (float) $count->total_aed], null, 'A'.$row++, true);
        $sheet->fromArray(['Counted (OMR)', (float) $count->total_omr], null, 'A'.$row++, true);
        $sheet->fromArray(['Expected Cash (AED)', (float) $count->expected_aed], null, 'A'.$row++, true);
        $sheet->fromArray([
            'Difference',
            $difference == 0.0 ? 'Balanced' : ($difference > 0 ? 'Over ' : 'Short ').number_format(abs($difference), 2),
        ], null, 'A'.$row++, true);
        if ($count->remarks) {
            $sheet->fromArray(['Remarks', $count->remarks], null, 'A'.$row++, true);
        }

        foreach (range(1, 4) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
    }
}
