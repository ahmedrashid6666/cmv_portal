<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerEntryDetail extends Model
{
    use HasFactory;

    protected $fillable = ['ledger_entry_id', 'detail_date', 'description', 'amount'];

    protected function casts(): array
    {
        return [
            'detail_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function entry()
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }
}
