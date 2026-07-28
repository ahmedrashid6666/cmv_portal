<?php

namespace App\Http\Requests;

use App\Models\CustomField;
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
        $rules = [
            'transaction_date' => ['required', 'date'],
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'boe_no' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['required', 'exists:customers,id'],
            'reference_id' => ['nullable', 'exists:references,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],

            'customs_fees' => ['required', 'numeric', 'min:0'],
            'gov_fees' => ['required', 'numeric', 'min:0'],
            'gov_bank_id' => ['nullable', 'exists:banks,id'],
            'profit' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', Rule::in(['AED', 'OMR'])],

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
            'custom_data' => ['nullable', 'array'],
        ];

        // Dynamic validation for admin-defined custom fields
        foreach (CustomField::active()->get() as $field) {
            $rules['custom_data.'.$field->key] = $field->rules();
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        // Drop custom_data keys that are not defined fields.
        if ($this->has('custom_data') && is_array($this->input('custom_data'))) {
            $allowed = CustomField::active()->pluck('key')->all();
            $this->merge([
                'custom_data' => array_intersect_key($this->input('custom_data'), array_flip($allowed)),
            ]);
        }
    }
}
