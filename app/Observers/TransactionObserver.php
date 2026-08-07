<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\TransactionCalculator;

class TransactionObserver
{
    public function __construct(private TransactionCalculator $calc) {}

    /**
     * Derive vat_amount and total_amount from the raw income inputs before
     * every write, so they can never be hand-edited into an inconsistent state.
     */
    public function saving(Transaction $transaction): void
    {
        // taxable base = customs + gov fees + other amount + profit
        $taxable = $this->calc->totalAmount(
            $transaction->customs_fees ?? 0,
            $transaction->gov_fees ?? 0,
            $transaction->other_amount ?? 0,
            $transaction->profit ?? 0,
            0,
        );

        $transaction->vat_amount = $this->calc->vatAmount($taxable, $transaction->vat_rate ?? 0);

        $transaction->total_amount = $this->calc->totalAmount(
            $transaction->customs_fees ?? 0,
            $transaction->gov_fees ?? 0,
            $transaction->other_amount ?? 0,
            $transaction->profit ?? 0,
            $transaction->vat_amount,
        );
    }
}
