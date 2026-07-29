<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A manual money movement on a bank account: an "in" (deposit) adds to the
 * bank's balance, an "out" (withdrawal / charge) subtracts from it.
 */
class BankEntry extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'bank_id', 'entry_date', 'item', 'description', 'direction', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
