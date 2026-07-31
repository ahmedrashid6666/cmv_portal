<?php

namespace App\Http\Controllers;

use App\Models\FinalCalculation;
use App\Services\FinalCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class FinalCalculationController extends Controller
{
    public function __construct(private FinalCalculationService $service) {}

    public function index(Request $request)
    {
        $date = $request->date('date')?->toDateString() ?? Carbon::today()->toDateString();
        $snapshot = FinalCalculation::whereDate('calc_date', $date)->first();

        // A saved day loads its frozen figures; a fresh day (or ?fresh=1, the
        // "recompute from live data" action) is auto-filled from live balances.
        $data = ($snapshot && ! $request->boolean('fresh'))
            ? $snapshot->data
            : $this->service->defaults($date);

        // The Daily Work Sheet Bal row's cash cells always reflect the date's
        // actual Daily Cash Count, even for an already-saved snapshot — so a
        // cash count entered/updated after saving still shows up here.
        $data = $this->service->withLiveCashCount($data, $date);

        return Inertia::render('Books/FinalCalculation/Index', [
            'date' => $date,
            'data' => $data,
            'totals' => $this->service->compute($data),
            'saved' => (bool) $snapshot,
            'savedId' => $snapshot?->id,
            'defaultOmrRate' => FinalCalculationService::DEFAULT_OMR_RATE,
            'history' => FinalCalculation::latest('calc_date')->limit(20)->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'date' => $c->calc_date->format('Y-m-d'),
                    'liquid_cash' => (float) $c->liquid_cash,
                    'cash_extra' => (float) $c->cash_extra,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'calc_date' => ['required', 'date'],
            'data' => ['required', 'array'],
            'data.rows' => ['required', 'array'],
            'data.omr_rate' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $data = $validated['data'];
        $data['remarks'] = $validated['remarks'] ?? ($data['remarks'] ?? null);
        $totals = $this->service->compute($data);

        FinalCalculation::updateOrCreate(
            ['calc_date' => $validated['calc_date']],
            array_merge($totals, [
                'data' => $data,
                'remarks' => $data['remarks'],
                'created_by' => $request->user()->id,
            ]),
        );

        return back()->with('success', 'Final calculation saved for '.$validated['calc_date'].'.');
    }

    public function pdf(FinalCalculation $finalCalculation)
    {
        return Pdf::loadView('final-calculation.pdf', [
            'calc' => $finalCalculation,
            'totals' => $this->service->compute($finalCalculation->data),
        ])->download('final-calculation-'.$finalCalculation->calc_date->format('Y-m-d').'.pdf');
    }
}
