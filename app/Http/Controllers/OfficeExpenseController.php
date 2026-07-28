<?php

namespace App\Http\Controllers;

use App\Models\OfficeExpense;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfficeExpenseController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', Rule::in(['AED', 'OMR'])],
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'remarks' => ['nullable', 'string'],
        ]);

        OfficeExpense::create([
            ...$data,
            'currency' => $data['currency'] ?? 'AED',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('operations.index', ['type' => 'office-expenses'])
            ->with('success', 'Office expense recorded.');
    }

    public function destroy(OfficeExpense $officeExpense)
    {
        $officeExpense->delete();

        return back()->with('success', 'Office expense moved to the recycle bin.');
    }
}
