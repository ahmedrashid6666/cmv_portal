<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A no-invoice petty cash note (date, item, description, in/out amount).
 * Record-keeping only — it never feeds into BalanceService or
 * FinalCalculationService, so it has no effect on the Cash & Bank Book,
 * DWS balance, or the Final Calculation worksheet.
 */
class PettyCashEntry extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'entry_date', 'item', 'description', 'in_amount', 'out_amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'in_amount' => 'decimal:2',
            'out_amount' => 'decimal:2',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return 'Petty cash '.$this->entry_date?->format('Y-m-d').' — '.$this->item;
    }
}
