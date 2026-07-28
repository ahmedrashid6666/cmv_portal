<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class CashCount extends Model
{
    use Auditable;

    /** Note/coin denominations per currency (highest → lowest). */
    public const DENOMINATIONS = [
        'AED' => [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.5, 0.25],
        'OMR' => [50, 20, 10, 5, 1, 0.5, 0.1, 0.05],
    ];

    protected $fillable = [
        'count_date', 'lines', 'extras', 'total_aed', 'total_omr', 'expected_aed', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date:Y-m-d',
            'lines' => 'array',
            'extras' => 'array',
            'total_aed' => 'decimal:2',
            'total_omr' => 'decimal:3',
            'expected_aed' => 'decimal:2',
        ];
    }

    /** Total counted for a currency = Σ(denomination × qty) + Σ(extras). */
    public static function totalFor(string $currency, array $lines, array $extras): float
    {
        $total = 0.0;
        foreach (self::DENOMINATIONS[$currency] ?? [] as $denom) {
            $qty = (float) ($lines[$currency][(string) $denom] ?? 0);
            $total += $denom * $qty;
        }
        foreach ($extras[$currency] ?? [] as $row) {
            $total += (float) ($row['amount'] ?? 0);
        }

        return round($total, $currency === 'OMR' ? 3 : 2);
    }

    public function auditLabel(): string
    {
        return 'Cash count '.$this->count_date?->format('Y-m-d');
    }
}
