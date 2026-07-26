<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;

it('lets a super admin save company settings', function () {
    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->put(route('settings.company'), [
            'company_name' => 'CMV Shipping LLC',
            'currency' => 'AED',
            'vat_rate' => 5,
            'cash_opening_balance' => 1000,
            'low_cash_threshold' => 500,
            'large_expense_threshold' => 1000,
        ])->assertRedirect();

    expect(Setting::get('company_name'))->toBe('CMV Shipping LLC')
        ->and(Setting::get('vat_rate'))->toBe('5')
        ->and(Setting::get('cash_opening_balance'))->toBe('1000');
});

it('forbids non super admins from settings', function () {
    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('settings.index'))->assertForbidden();
});

it('rejects a bad database connection without saving', function () {
    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->from(route('settings.index'))
        ->put(route('settings.database'), [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'definitely_not_a_real_db_'.uniqid(),
            'username' => 'nope',
            'password' => 'wrong',
        ])->assertSessionHasErrors('database');
});
