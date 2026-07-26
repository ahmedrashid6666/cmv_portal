<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'invoice_no' => $this->invoice_no,
            'boe_no' => $this->boe_no,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_id' => $this->customer_id,
            'reference_id' => $this->reference_id,
            'vehicle_id' => $this->vehicle_id,
            'payment_method_id' => $this->payment_method_id,
            'customs_fees' => (float) $this->customs_fees,
            'gov_fees' => (float) $this->gov_fees,
            'profit' => (float) $this->profit,
            'vat_rate' => (float) $this->vat_rate,
            'vat_amount' => (float) $this->vat_amount,
            'total_amount' => (float) $this->total_amount,
            'grand_total' => (float) $this->grand_total,
            'net_profit' => (float) $this->net_profit,
            'credit_amount' => (float) $this->credit_amount,
            'credit_outstanding' => (float) $this->creditOutstanding(),
            'expenses' => $this->whenLoaded('expenses'),
            'commissions' => $this->whenLoaded('commissions'),
        ];
    }
}
