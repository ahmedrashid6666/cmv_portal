<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerPayment extends Model
{
    protected $fillable = ['ledger_entry_id', 'payment_date', 'amount', 'payment_method_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['payment_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    public function entry()
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
