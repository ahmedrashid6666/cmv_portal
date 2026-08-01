<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A dated snapshot of the "Final Calculation" reconciliation worksheet.
 *
 * `data` holds every input row as saved (frozen point-in-time), while the
 * decimal columns cache the six computed totals for the history list. The math
 * lives in FinalCalculationService::compute() so it runs identically on save
 * and in the browser.
 */
class FinalCalculation extends Model
{
    use Auditable;

    protected $fillable = [
        'calc_date', 'data', 'total_amount', 'total_ac_balance', 'total_debt_exp',
        'liquid_cash', 'cash_counted', 'cash_extra', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'calc_date' => 'date:Y-m-d',
            'data' => 'array',
            'total_amount' => 'decimal:2',
            'total_ac_balance' => 'decimal:2',
            'total_debt_exp' => 'decimal:2',
            'liquid_cash' => 'decimal:2',
            'cash_counted' => 'decimal:2',
            'cash_extra' => 'decimal:2',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return 'Final Calculation '.$this->calc_date?->format('Y-m-d');
    }

    /**
     * `data` is the full worksheet (every row, re-saved on every edit) — a
     * raw diff of it is unreadable. The six totals + remarks columns are
     * logged individually and already summarise what changed.
     *
     * @return array<int, string>
     */
    public function auditExclude(): array
    {
        return ['data'];
    }
}
