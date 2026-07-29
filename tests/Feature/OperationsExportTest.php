<?php

use App\Enums\Role;
use App\Models\Transaction;
use App\Models\User;

beforeEach(fn () => $this->actor = User::factory()->role(Role::ACCOUNTANT)->create());

it('exports the transactions tab as an Excel file', function () {
    Transaction::factory()->count(3)->create();

    $this->actingAs($this->actor)
        ->get(route('operations.export', ['format' => 'xlsx', 'type' => 'transactions']))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports the transactions tab as a PDF', function () {
    Transaction::factory()->count(3)->create();

    $this->actingAs($this->actor)
        ->get(route('operations.export', ['format' => 'pdf', 'type' => 'transactions']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('exports other tabs too', function () {
    foreach (['invoices', 'credits', 'daily-credit', 'borrowed', 'office-expenses'] as $type) {
        $this->actingAs($this->actor)
            ->get(route('operations.export', ['format' => 'xlsx', 'type' => $type]))
            ->assertOk();
    }
});

it('rejects an unknown export format', function () {
    $this->actingAs($this->actor)
        ->get(route('operations.export', ['format' => 'txt', 'type' => 'transactions']))
        ->assertNotFound();
});
