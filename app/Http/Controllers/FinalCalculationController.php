<?php

namespace App\Http\Controllers;

use App\Models\CashCount;
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

        // The counted-cash cells always reflect the date's actual Daily Cash
        // Count, even for an already-saved snapshot — so a cash count
        // entered/updated after saving still shows up here.
        $data = $this->service->withLiveCashCount($data, $date);

        $count = CashCount::whereDate('count_date', $date)->first();

        // Cast numeric data values to float to ensure type consistency
        // through serialization, particularly for values overlaid from CashCount
        if (isset($data['aed_counted'])) {
            $data['aed_counted'] = (float) $data['aed_counted'];
        }
        if (isset($data['omr_counted'])) {
            $data['omr_counted'] = (float) $data['omr_counted'];
        }
        if (isset($data['omr_rate'])) {
            $data['omr_rate'] = (float) $data['omr_rate'];
        }

        return Inertia::render('Books/FinalCalculation/Index', [
            'date' => $date,
            'data' => $data,
            'totals' => $this->service->compute($data),
            'saved' => (bool) $snapshot,
            'savedId' => $snapshot?->id,
            'defaultOmrRate' => FinalCalculationService::DEFAULT_OMR_RATE,
            'denominations' => CashCount::DENOMINATIONS,
            // Full CashCount shape (not just lines/bundles) so the embedded
            // widget can resubmit extras/remarks unchanged even though it
            // doesn't render them — see Global Constraints.
            'count' => $count ? [
                'lines' => $count->lines,
                'extras' => $count->extras ?? ['AED' => [], 'OMR' => []],
                'bundles' => $count->bundles ?? ['AED' => [], 'OMR' => []],
                'remarks' => $count->remarks,
            ] : null,
            'history' => FinalCalculation::latest('calc_date')->limit(20)->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'date' => $c->calc_date->format('Y-m-d'),
                    'total_cash_balance' => (float) $c->liquid_cash,
                    'cash_extra' => (float) $c->cash_extra,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'calc_date' => ['required', 'date'],
            'data' => ['required', 'array'],
            'data.opening_balance' => ['required', 'numeric'],
            'data.total_income' => ['required', 'numeric'],
            'data.customs_gov_fees' => ['required', 'numeric'],
            'data.credit_unpaid' => ['required', 'numeric'],
            'data.office_expenses' => ['required', 'numeric'],
            'data.borrowed_amount' => ['required', 'numeric'],
            'data.daily_credit_pending' => ['required', 'numeric'],
            'data.bank_ac_balance' => ['required', 'numeric'],
            'data.cdr_ac_balance' => ['required', 'numeric'],
            'data.aed_counted' => ['nullable', 'numeric'],
            'data.omr_counted' => ['nullable', 'numeric'],
            'data.omr_rate' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $data = $validated['data'];
        $data['remarks'] = $validated['remarks'] ?? ($data['remarks'] ?? null);

        // Cast all numeric data values to float before storing to ensure
        // consistent type handling during deserialization
        foreach (['opening_balance', 'total_income', 'customs_gov_fees', 'credit_unpaid', 'office_expenses', 'borrowed_amount', 'daily_credit_pending', 'bank_ac_balance', 'cdr_ac_balance', 'aed_counted', 'omr_counted', 'omr_rate'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $data[$key] = (float) $data[$key];
            }
        }

        $totals = $this->service->compute($data);

        FinalCalculation::updateOrCreate(
            ['calc_date' => $validated['calc_date']],
            [
                'data' => $data,
                'total_amount' => $totals['total_amount'],
                'liquid_cash' => $totals['total_cash_balance'],
                'cash_counted' => $totals['cash_counted'],
                'cash_extra' => $totals['cash_extra'],
                'remarks' => $data['remarks'],
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Final calculation saved for '.$validated['calc_date'].'.');
    }

    public function destroy(FinalCalculation $finalCalculation)
    {
        $date = $finalCalculation->calc_date->format('Y-m-d');
        $finalCalculation->delete();

        return back()->with('success', 'Final calculation snapshot for '.$date.' deleted.');
    }

    public function pdf(FinalCalculation $finalCalculation)
    {
        return Pdf::loadView('final-calculation.pdf', [
            'calc' => $finalCalculation,
            'totals' => $this->service->compute($finalCalculation->data),
        ])->download('final-calculation-'.$finalCalculation->calc_date->format('Y-m-d').'.pdf');
    }
}
