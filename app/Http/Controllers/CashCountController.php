<?php

namespace App\Http\Controllers;

use App\Models\CashCount;
use App\Services\FinalCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

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
            'history' => CashCount::latest('count_date')->limit(15)->get(['id', 'count_date', 'total_aed', 'total_omr', 'expected_aed', 'extras'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'date' => $c->count_date->format('Y-m-d'),
                    'total_aed' => (float) $c->total_aed,
                    'total_omr' => (float) $c->total_omr,
                    'variance' => round((float) $c->total_aed - (float) $c->expected_aed, 2),
                    'extras' => $c->extras ?? ['AED' => [], 'OMR' => []],
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
            'denominations' => CashCount::DENOMINATIONS,
        ])->download('cash-count-'.$cashCount->count_date->format('Y-m-d').'.pdf');
    }
}
