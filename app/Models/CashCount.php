<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class CashCount extends Model
{
    use Auditable;

    /** Note/coin denominations per currency (highest → lowest). */
    public const DENOMINATIONS = [
        'AED' => [1000, 500, 200, 100, 50, 20, 10, 5, 1],
        'OMR' => [50, 20, 10, 5, 1, 0.5, 0.1, 0.05],
    ];

    protected $fillable = [
        'count_date', 'lines', 'extras', 'bundles', 'total_aed', 'total_omr', 'expected_aed', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date:Y-m-d',
            'lines' => 'array',
            'extras' => 'array',
            'bundles' => 'array',
            'total_aed' => 'decimal:2',
            'total_omr' => 'decimal:3',
            'expected_aed' => 'decimal:2',
        ];
    }

    /** Total counted for a currency = Σ(denomination × qty) + Σ(bundle amounts). Slips/extras are reference only. */
    public static function totalFor(string $currency, array $lines, array $extras, array $bundles = []): float
    {
        $total = 0.0;
        foreach (self::DENOMINATIONS[$currency] ?? [] as $denom) {
            $qty = (float) ($lines[$currency][(string) $denom] ?? 0);
            $total += $denom * $qty;
        }
        foreach ($bundles[$currency] ?? [] as $bundle) {
            $total += (float) ($bundle['amount'] ?? 0);
        }

        return round($total, $currency === 'OMR' ? 3 : 2);
    }

    /**
     * The IN total, OUT total and their net across the slip/extras list for a
     * currency. Reference only, same as the slips themselves; not part of
     * totalFor(). Extras alternate IN (even index) / OUT (odd index),
     * mirroring the two-column layout on the Daily Cash Slip page.
     *
     * @return array{in: float, out: float, balance: float}
     */
    public static function extrasTotalsFor(string $currency, array $extras): array
    {
        $in = 0.0;
        $out = 0.0;
        foreach (array_values($extras[$currency] ?? []) as $i => $item) {
            $amount = (float) ($item['amount'] ?? 0);
            if ($i % 2 === 0) {
                $in += $amount;
            } else {
                $out += $amount;
            }
        }

        return ['in' => round($in, 2), 'out' => round($out, 2), 'balance' => round($in - $out, 2)];
    }

    /**
     * Net IN − OUT — the "Balance Amount" shown under each currency's
     * Bundles/Slips table.
     */
    public static function extrasBalanceFor(string $currency, array $extras): float
    {
        return self::extrasTotalsFor($currency, $extras)['balance'];
    }

    public function auditLabel(): string
    {
        return 'Cash count '.$this->count_date?->format('Y-m-d');
    }

    /**
     * `lines`/`extras`/`bundles` hold every denomination count and bundle/slip
     * row — a raw diff of them is unreadable. total_aed/total_omr/expected_aed
     * are logged individually and already summarise what changed.
     *
     * @return array<int, string>
     */
    public function auditExclude(): array
    {
        return ['lines', 'extras', 'bundles'];
    }
}
