<?php

namespace App\Http\Controllers;

use App\Models\CashCount;
use App\Services\BalanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class CashCountController extends Controller
{
    public function index(Request $request, BalanceService $balances)
    {
        $date = $request->date('date')?->toDateString() ?? Carbon::today()->toDateString();
        $count = CashCount::whereDate('count_date', $date)->first();

        return Inertia::render('CashCount/Index', [
            'date' => $date,
            'denominations' => CashCount::DENOMINATIONS,
            'count' => $count ? [
                'lines' => $count->lines,
                'extras' => $count->extras ?? ['AED' => [], 'OMR' => []],
                'remarks' => $count->remarks,
            ] : null,
            'expectedAed' => (float) $balances->cashBalance(),
            'history' => CashCount::latest('count_date')->limit(15)->get(['id', 'count_date', 'total_aed', 'total_omr', 'expected_aed'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'date' => $c->count_date->format('Y-m-d'),
                    'total_aed' => (float) $c->total_aed,
                    'total_omr' => (float) $c->total_omr,
                    'variance' => round((float) $c->total_aed - (float) $c->expected_aed, 2),
                ]),
        ]);
    }

    public function store(Request $request, BalanceService $balances)
    {
        $data = $request->validate([
            'count_date' => ['required', 'date'],
            'lines' => ['required', 'array'],
            'extras' => ['nullable', 'array'],
            'remarks' => ['nullable', 'string'],
        ]);

        $lines = $data['lines'];
        $extras = $data['extras'] ?? ['AED' => [], 'OMR' => []];

        CashCount::updateOrCreate(
            ['count_date' => $data['count_date']],
            [
                'lines' => $lines,
                'extras' => $extras,
                'total_aed' => CashCount::totalFor('AED', $lines, $extras),
                'total_omr' => CashCount::totalFor('OMR', $lines, $extras),
                'expected_aed' => (float) $balances->cashBalance(),
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Cash count saved for '.$data['count_date'].'.');
    }

    public function pdf(CashCount $cashCount)
    {
        return Pdf::loadView('cash-count.pdf', [
            'count' => $cashCount,
            'denominations' => CashCount::DENOMINATIONS,
        ])->download('cash-count-'.$cashCount->count_date->format('Y-m-d').'.pdf');
    }
}
