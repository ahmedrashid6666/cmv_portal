<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;

beforeEach(fn () => $this->admin = User::factory()->role(Role::SUPER_ADMIN)->create());

it('filters master records by the search term', function () {
    Customer::create(['name' => 'ALPHA LLC']);
    Customer::create(['name' => 'BETA TRADING']);

    $this->actingAs($this->admin)
        ->get(route('masters.index', ['master' => 'customers', 'search' => 'ALPHA']))
        ->assertInertia(fn ($p) => $p->where('rows.data', fn ($rows) => count($rows) === 1
            && $rows[0]['name'] === 'ALPHA LLC')
            ->where('filters.search', 'ALPHA'));
});

it('searches across all text columns, not just the name', function () {
    Customer::create(['name' => 'ALPHA LLC', 'contact' => '+971 50 111 2222']);
    Customer::create(['name' => 'BETA TRADING', 'contact' => '+968 90 000 0000']);

    // Matches by the Contact column even though the name does not contain it.
    $this->actingAs($this->admin)
        ->get(route('masters.index', ['master' => 'customers', 'search' => '111 2222']))
        ->assertInertia(fn ($p) => $p->where('rows.data', fn ($rows) => count($rows) === 1
            && $rows[0]['name'] === 'ALPHA LLC'));
});
