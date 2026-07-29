<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;

it('lets a super admin set the cash opening balance from the cash book', function () {
    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->put(route('books.cash-opening'), ['cash_opening_balance' => 1500.50])
        ->assertRedirect();

    expect((float) Setting::get('cash_opening_balance'))->toBe(1500.5);
});

it('exposes the current opening balance to the cash book page', function () {
    Setting::put('cash_opening_balance', 999);

    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->get(route('books.cashbank'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('cashOpening', fn ($v) => (float) $v === 999.0));
});

it('forbids non-super-admins from setting the cash opening balance', function () {
    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->put(route('books.cash-opening'), ['cash_opening_balance' => 100])
        ->assertForbidden();
});
