<?php

use App\Services\FinalCalculationService;

/**
 * Locks the reconciliation math to the accountant's spreadsheet screenshot:
 * Opening Balance -> Total Income -> ... -> Total Cash Balance In Hand.
 */
it('reproduces the spreadsheet totals exactly', function () {
    $data = [
        'opening_balance' => 64061,
        'total_income' => 15793,
        'customs_gov_fees' => 11688,
        'credit_unpaid' => 8850,
        'office_expenses' => 2434,
        'borrowed_amount' => 89700,
        'daily_credit_pending' => 58069,
        'bank_ac_balance' => 56684,
        'cdr_ac_balance' => 19927,
        'aed_counted' => 0,
        'omr_counted' => 0,
        'omr_rate' => 9.5238,
    ];

    $t = app(FinalCalculationService::class)->compute($data);

    expect($t['total_amount'])->toBe(56882.0)
        ->and($t['total_balance_amount'])->toBe(88513.0)
        ->and($t['total_cash_balance'])->toBe(11902.0)
        ->and($t['cash_counted'])->toBe(0.0)
        ->and($t['cash_extra'])->toBe(-11902.0);
});

it('converts OMR counted cash to AED at the given rate', function () {
    $t = app(FinalCalculationService::class)->compute([
        'opening_balance' => 0, 'total_income' => 0, 'customs_gov_fees' => 0,
        'credit_unpaid' => 0, 'office_expenses' => 0, 'borrowed_amount' => 0,
        'daily_credit_pending' => 0, 'bank_ac_balance' => 0, 'cdr_ac_balance' => 0,
        'aed_counted' => 100, 'omr_counted' => 10, 'omr_rate' => 9.5,
    ]);

    expect($t['cash_counted'])->toBe(195.0)  // 100 + 10 * 9.5
        ->and($t['total_cash_balance'])->toBe(0.0)
        ->and($t['cash_extra'])->toBe(195.0);
});

it('treats missing fields as zero and defaults the OMR rate', function () {
    $t = app(FinalCalculationService::class)->compute([]);

    expect($t['total_amount'])->toBe(0.0)
        ->and($t['total_balance_amount'])->toBe(0.0)
        ->and($t['total_cash_balance'])->toBe(0.0)
        ->and($t['omr_rate'])->toBe(FinalCalculationService::DEFAULT_OMR_RATE)
        ->and($t['cash_extra'])->toBe(0.0);
});
