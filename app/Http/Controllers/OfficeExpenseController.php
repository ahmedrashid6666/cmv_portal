<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\ExpenseCategory;
use App\Models\OfficeExpense;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OfficeExpenseController extends Controller
{
    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', Rule::in(['AED', 'OMR'])],
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'contact_numbers' => ['nullable', 'array'],
            'contact_numbers.*' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string'],
        ];
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

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['contact_numbers'] = $this->cleanNumbers($data['contact_numbers'] ?? []);

        OfficeExpense::create([
            ...$data,
            'currency' => $data['currency'] ?? 'AED',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('operations.index', ['type' => 'office-expenses'])
            ->with('success', 'Office expense recorded.');
    }

    public function edit(OfficeExpense $officeExpense)
    {
        return Inertia::render('OfficeExpense/Edit', [
            'officeExpense' => [
                'id' => $officeExpense->id,
                'expense_date' => $officeExpense->expense_date->format('Y-m-d'),
                'expense_category_id' => $officeExpense->expense_category_id,
                'description' => $officeExpense->description,
                'amount' => (float) $officeExpense->amount,
                'currency' => $officeExpense->currency,
                'payment_method_id' => $officeExpense->payment_method_id,
                'bank_id' => $officeExpense->bank_id,
                'contact_numbers' => $officeExpense->contact_numbers,
                'remarks' => $officeExpense->remarks,
            ],
            'expenseCategories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'type']),
            'banks' => Bank::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, OfficeExpense $officeExpense)
    {
        $data = $request->validate($this->rules());
        $data['contact_numbers'] = $this->cleanNumbers($data['contact_numbers'] ?? []);

        $officeExpense->update([
            ...$data,
            'currency' => $data['currency'] ?? 'AED',
        ]);

        return redirect()->route('operations.index', ['type' => 'office-expenses'])
            ->with('success', 'Office expense updated.');
    }

    public function destroy(OfficeExpense $officeExpense)
    {
        $officeExpense->delete();

        return back()->with('success', 'Office expense moved to the recycle bin.');
    }
}
