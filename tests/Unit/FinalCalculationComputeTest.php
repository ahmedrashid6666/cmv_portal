<?php

use App\Services\FinalCalculationService;

/**
 * Locks the reconciliation math to the accountant's workbook
 * (ACCOUNT WORKBOOK.xlsm, sheet 01-07-2026, rows 174–195).
 */
it('reproduces the workbook FINAL CALCULATION totals exactly', function () {
    $data = [
        'omr_rate' => 9.5238,
        'rows' => [
            ['key' => 'dws_bal', 'amount' => 14345],
            ['key' => 'borrowed', 'amount' => 71955],
            ['key' => 'daily_credit', 'debt_exp' => 39329, 'cash_aed' => 15375],
            ['key' => 'bank_rak', 'ac_balance' => 7602],
            ['key' => 'bank_adcb', 'ac_balance' => 12061],
            ['key' => 'bank_fab', 'ac_balance' => 2476],
            ['key' => 'bank_dib', 'ac_balance' => 1398],
            ['key' => 'bank_cdr', 'ac_balance' => 3612, 'currency' => 'OMR'],
            ['key' => 'exp_rak', 'debt_exp' => 0, 'cash_omr' => 250],
            ['key' => 'exp_adcb', 'debt_exp' => 0],
            ['key' => 'salary', 'ac_balance' => 2000],
        ],
    ];

    $t = app(FinalCalculationService::class)->compute($data);

    expect($t['total_amount'])->toBe(86300.0)
        ->and($t['total_ac_balance'])->toBe(29149.0)
        ->and($t['total_debt_exp'])->toBe(39329.0)
        ->and($t['liquid_cash'])->toBe(17822.0)
        ->and($t['cash_counted'])->toBe(17755.95)   // 15375 + 250 × 9.5238
        ->and($t['cash_extra'])->toBe(-66.05);       // 17755.95 − 17822
});

it('treats missing column cells as zero', function () {
    $t = app(FinalCalculationService::class)->compute([
        'omr_rate' => 10,
        'rows' => [
            ['key' => 'a', 'amount' => 100],
            ['key' => 'b', 'ac_balance' => 40, 'debt_exp' => 10],
            ['key' => 'c', 'cash_aed' => 5, 'cash_omr' => 2],
        ],
    ]);

    expect($t['total_amount'])->toBe(100.0)
        ->and($t['total_ac_balance'])->toBe(40.0)
        ->and($t['total_debt_exp'])->toBe(10.0)
        ->and($t['liquid_cash'])->toBe(50.0)          // 100 − (40 + 10)
        ->and($t['cash_counted'])->toBe(25.0)         // 5 + 2 × 10
        ->and($t['cash_extra'])->toBe(-25.0);         // 25 − 50
});
