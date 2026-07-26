<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Setting;

it('persists a customer with an opening balance', function () {
    $c = Customer::create(['name' => 'ESQUBE INDUSTRIES LLC', 'opening_balance' => 110]);

    expect(Customer::find($c->id)->name)->toBe('ESQUBE INDUSTRIES LLC')
        ->and((float) $c->opening_balance)->toBe(110.0);
});

it('persists a payment method with a balance-bucket type', function () {
    $m = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);

    expect($m->type)->toBe('cash');
});

it('reads and writes settings via helpers', function () {
    Setting::put('vat_rate', 0);

    expect(Setting::get('vat_rate'))->toBe('0')
        ->and(Setting::get('missing_key', 'fallback'))->toBe('fallback');

    Setting::put('vat_rate', 5);
    expect(Setting::get('vat_rate'))->toBe('5');
});
