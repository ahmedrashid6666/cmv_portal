<?php

use App\Support\Money;

it('shows whole amounts without decimals and fractional amounts with two', function () {
    expect(Money::number(200))->toBe('200')
        ->and(Money::number(11450))->toBe('11,450')
        ->and(Money::number(200.5))->toBe('200.50')
        ->and(Money::number(200.25))->toBe('200.25')
        ->and(Money::number(0))->toBe('0');
});

it('omits the label for AED and appends other currency codes', function () {
    expect(Money::display(200, 'AED'))->toBe('200')
        ->and(Money::display(200, null))->toBe('200')
        ->and(Money::display(200.5, 'OMR'))->toBe('200.50 OMR')
        ->and(Money::display(200, 'OMR'))->toBe('200 OMR');
});
