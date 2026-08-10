<?php

namespace App\Rules;

use App\Models\PaymentMethod;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A bank must be selected whenever the sibling payment_method_id resolves to
 * a bank-type PaymentMethod — mirrors the existing OfficeExpense pattern
 * (conditionally-shown bank field), now enforced server-side everywhere a
 * bank-type payment method can be chosen.
 */
class RequiredBankForPaymentMethod implements ValidationRule
{
    /**
     * Run even when bank_id is empty/absent — otherwise Laravel's `nullable`
     * rule skips this check entirely before it ever sees the missing value.
     */
    public bool $implicit = true;

    public function __construct(private mixed $paymentMethodId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->paymentMethodId || $value) {
            return;
        }

        if (PaymentMethod::find($this->paymentMethodId)?->type === 'bank') {
            $fail('Please select which bank this payment was made to/from.');
        }
    }
}
