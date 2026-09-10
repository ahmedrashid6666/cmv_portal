<?php

namespace App\Support;

/**
 * Spells out a money amount for a printed statement/cheque, e.g.
 * 1234.50 → "One Thousand Two Hundred Thirty Four Dirhams and Fifty Fils Only".
 * Self-contained (no ext-intl dependency — not guaranteed on shared hosting).
 */
class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function convert(float $amount, string $currency = 'AED'): string
    {
        $amount = round(abs($amount), 2);
        $whole = (int) floor($amount);
        $fraction = (int) round(($amount - $whole) * 100);

        [$unit, $subunit] = $currency === 'OMR' ? ['Rial', 'Baisa'] : ['Dirham', 'Fils'];

        // The subunit name (Fils/Baisa) is conventionally invariant — unlike
        // the main currency unit, it's never written "Fils" vs "Fil".
        $result = self::spell($whole).' '.$unit.($whole === 1 ? '' : 's');
        if ($fraction > 0) {
            $result .= ' and '.self::spell($fraction).' '.$subunit;
        }

        return $result.' Only';
    }

    private static function spell(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        if ($n < 20) {
            return self::ONES[$n];
        }
        if ($n < 100) {
            return trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
        }
        if ($n < 1000) {
            return trim(self::ONES[intdiv($n, 100)].' Hundred'.($n % 100 ? ' '.self::spell($n % 100) : ''));
        }
        if ($n < 1000000) {
            return trim(self::spell(intdiv($n, 1000)).' Thousand'.($n % 1000 ? ' '.self::spell($n % 1000) : ''));
        }

        return trim(self::spell(intdiv($n, 1000000)).' Million'.($n % 1000000 ? ' '.self::spell($n % 1000000) : ''));
    }
}
