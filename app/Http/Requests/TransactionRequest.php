<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces role
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transaction_date' => ['required', 'date'],
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'boe_no' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['required', 'exists:customers,id'],
            'reference_id' => ['nullable', 'exists:references,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],

            'customs_fees' => ['required', 'numeric', 'min:0'],
            'gov_fees' => ['required', 'numeric', 'min:0'],
            'profit' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],

            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'credit_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],

            'expenses' => ['array'],
            'expenses.*.expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'expenses.*.description' => ['nullable', 'string', 'max:255'],
            'expenses.*.amount' => ['nullable', 'numeric', 'min:0'],

            'commissions' => ['array'],
            'commissions.*.label' => ['nullable', 'string', 'max:255'],
            'commissions.*.amount' => ['nullable', 'numeric', 'min:0'],
            'commissions.*.type' => ['nullable', Rule::in(['charged_to_customer', 'paid_to_reference'])],
            'commissions.*.reference_id' => ['nullable', 'exists:references,id'],
        ];
    }
}
