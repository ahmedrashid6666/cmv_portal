<?php

use App\Services\TransactionCalculator;

beforeEach(fn () => $this->calc = new TransactionCalculator());

it('matches workbook row 7: customs 295, profit 50, vat 0, commission 25 to customer', function () {
    // taxable base = customs + gov + other + profit
    $vat = $this->calc->vatAmount('345.00', '0');
    expect($vat)->toBe('0.00');

    $total = $this->calc->totalAmount('295.00', '0.00', '0.00', '50.00', $vat);
    expect($total)->toBe('345.00');

    $grand = $this->calc->grandTotal($total, [
        ['type' => 'charged_to_customer', 'amount' => '25.00'],
    ]);
    expect($grand)->toBe('370.00');
});

it('matches workbook row 3: customs 245, profit 35, total 280, no commission', function () {
    $total = $this->calc->totalAmount('245', '0', '0', '35', '0');
    expect($total)->toBe('280.00');
    expect($this->calc->grandTotal($total, []))->toBe('280.00');
});

it('includes other_amount in the taxable base, same as gov_fees', function () {
    $total = $this->calc->totalAmount('245', '10', '15', '35', '0');
    expect($total)->toBe('305.00');
});

it('sums expenses', function () {
    expect($this->calc->totalExpenses([
        ['amount' => '27.00'],
        ['amount' => '3.50'],
    ]))->toBe('30.50');
});

it('separates commission charged-to-customer from paid-to-reference', function () {
    $commissions = [
        ['type' => 'charged_to_customer', 'amount' => '25.00'],
        ['type' => 'paid_to_reference', 'amount' => '10.00'],
    ];

    expect($this->calc->grandTotal('100.00', $commissions))->toBe('125.00')
        ->and($this->calc->commissionPayable($commissions))->toBe('10.00');
});

it('computes net profit net of expenses and payable commission', function () {
    expect($this->calc->netProfit('50.00', '30.00', '10.00'))->toBe('10.00');
});

it('computes credit outstanding after partial payments', function () {
    expect($this->calc->creditOutstanding('200.00', [
        ['amount' => '50.00'],
        ['amount' => '30.00'],
    ]))->toBe('120.00');

    expect($this->calc->creditOutstanding('200.00', []))->toBe('200.00');
});

it('applies a non-zero vat rate and rounds to 2dp', function () {
    expect($this->calc->vatAmount('100.00', '5'))->toBe('5.00')
        ->and($this->calc->vatAmount('99.99', '5'))->toBe('5.00')   // 4.9995 -> 5.00
        ->and($this->calc->vatAmount('333.33', '5'))->toBe('16.67'); // 16.6665 -> 16.67
});

it('handles integer and float inputs safely', function () {
    expect($this->calc->totalAmount(245, 0, 0, 35, 0))->toBe('280.00')
        ->and($this->calc->totalExpenses([['amount' => 10], ['amount' => 5.5]]))->toBe('15.50');
});
