<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionCommission extends Model
{
    use HasFactory;

    protected $fillable = ['transaction_id', 'label', 'amount', 'type', 'reference_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function reference()
    {
        return $this->belongsTo(Reference::class);
    }
}
