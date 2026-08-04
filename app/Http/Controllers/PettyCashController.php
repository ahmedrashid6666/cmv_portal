<?php

namespace App\Http\Controllers;

use App\Models\PettyCashEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
        return $request->validate([
            'entry_date' => ['required', 'date'],
            'item' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'in_amount' => ['nullable', 'numeric', 'min:0'],
            'out_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
    }
}
