<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates transactions together with their expense and
 * commission children, then recomputes the cached totals. Used by both the
 * web controller and the JSON API so behaviour is identical everywhere.
 */
class TransactionWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $transaction = Transaction::create($this->attributes($data));
            $this->syncChildren($transaction, $data);

            return $transaction->recomputeTotals()->load(['expenses', 'commissions']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $transaction->update($this->attributes($data));
            $transaction->expenses()->delete();
            $transaction->commissions()->delete();
            $this->syncChildren($transaction, $data);

            return $transaction->recomputeTotals()->load(['expenses', 'commissions']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'transaction_date' => $data['transaction_date'],
            'invoice_no' => $data['invoice_no'] ?? null,
            'boe_no' => $data['boe_no'] ?? null,
            'customer_id' => $data['customer_id'],
            'reference_id' => $data['reference_id'] ?? null,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'customs_fees' => $data['customs_fees'] ?? 0,
            'gov_fees' => $data['gov_fees'] ?? 0,
            'gov_bank_id' => $data['gov_bank_id'] ?? null,
            'profit' => $data['profit'] ?? 0,
            'vat_rate' => $data['vat_rate'] ?? 0,
            'currency' => $data['currency'] ?? 'AED',
            'payment_method_id' => $data['payment_method_id'],
            'credit_amount' => $data['credit_amount'] ?? 0,
            'remarks' => $data['remarks'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
            'custom_data' => $data['custom_data'] ?? null,
            'created_by' => $data['created_by'] ?? auth()->id(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncChildren(Transaction $transaction, array $data): void
    {
        foreach ($data['expenses'] ?? [] as $expense) {
            if (($expense['amount'] ?? null) === null && ($expense['description'] ?? null) === null) {
                continue;
            }
            $transaction->expenses()->create([
                'expense_category_id' => $expense['expense_category_id'] ?? null,
                'description' => $expense['description'] ?? null,
                'amount' => $expense['amount'] ?? 0,
            ]);
        }

        foreach ($data['commissions'] ?? [] as $commission) {
            if (($commission['amount'] ?? null) === null) {
                continue;
            }
            $transaction->commissions()->create([
                'label' => $commission['label'] ?? null,
                'amount' => $commission['amount'] ?? 0,
                'type' => $commission['type'] ?? 'charged_to_customer',
                'reference_id' => $commission['reference_id'] ?? null,
            ]);
        }
    }
}
