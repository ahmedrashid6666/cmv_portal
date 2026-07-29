<?php

namespace App\Support;

/**
 * Consistent money formatting for values rendered as plain strings (table cells,
 * exports). Mirrors the frontend `num`/`money` helpers:
 *  - no decimals for whole amounts, two decimals when there is a fractional part
 *  - AED (the default) is shown without a currency label
 *  - any other currency (e.g. OMR) is appended as a short code
 */
class Money
{
    public static function number(float|int|string|null $amount): string
    {
        $value = round((float) $amount, 2);
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;

        return number_format($value, $decimals);
    }

    public static function display(float|int|string|null $amount, ?string $currency = 'AED'): string
    {
        $number = self::number($amount);

        return (! $currency || $currency === 'AED') ? $number : $number.' '.$currency;
    }
}
