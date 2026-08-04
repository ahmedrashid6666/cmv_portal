<?php

use App\Enums\Role;
use App\Models\LedgerEntry;
use App\Models\User;

it('updates an entry when a newly added detail row is left blank', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();
    $entry = LedgerEntry::create([
        'type' => 'daily_credit', 'entry_date' => '2026-08-04', 'party_name' => 'Debug Test',
        'total_amount' => 100, 'paid_amount' => 0, 'balance_amount' => 100, 'status' => 'pending', 'currency' => 'AED',
    ]);
    $entry->details()->create(['detail_date' => '2026-08-04', 'description' => 'row1', 'amount' => 100, 'returned_amount' => 0]);

    $this->actingAs($admin)
        ->from(route('ledger.index', 'daily-credit'))
        ->putJson(route('ledger.update', ['daily-credit', $entry->id]), [
            'entry_date' => '2026-08-04',
            'party_name' => 'Debug Test',
            'total_amount' => '100',
            'currency' => 'AED',
            'paid_amount' => 0,
            'details' => [
                ['detail_date' => '2026-08-04', 'description' => 'row1', 'amount' => '100', 'returned_amount' => 0],
                ['detail_date' => '2026-08-04', 'description' => '', 'amount' => '', 'returned_amount' => ''],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ledger.index', 'daily-credit'));

    expect($entry->fresh()->details)->toHaveCount(1);
});

it('adds a first detail row to a legacy entry that had none, during an edit', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();
    $entry = LedgerEntry::create([
        'type' => 'daily_credit', 'entry_date' => '2026-08-04', 'party_name' => 'Legacy Test',
        'total_amount' => 500, 'paid_amount' => 0, 'balance_amount' => 500, 'status' => 'pending', 'currency' => 'AED',
    ]);
    expect($entry->details)->toHaveCount(0);

    $this->actingAs($admin)
        ->from(route('ledger.index', 'daily-credit'))
        ->putJson(route('ledger.update', ['daily-credit', $entry->id]), [
            'entry_date' => '2026-08-04',
            'party_name' => 'Legacy Test',
            'total_amount' => '500',
            'currency' => 'AED',
            'paid_amount' => 0,
            'details' => [
                ['detail_date' => '2026-08-04', 'description' => '', 'amount' => '', 'returned_amount' => ''],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ledger.index', 'daily-credit'));

    expect($entry->fresh()->details)->toHaveCount(0);
});

it('adds a new filled detail row alongside an existing one', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();
    $entry = LedgerEntry::create([
        'type' => 'borrowed', 'entry_date' => '2026-08-04', 'party_name' => 'Two Row Test',
        'total_amount' => 100, 'paid_amount' => 0, 'balance_amount' => 100, 'status' => 'pending', 'currency' => 'AED',
    ]);
    $entry->details()->create(['detail_date' => '2026-08-04', 'description' => 'row1', 'amount' => 100, 'returned_amount' => 0]);

    $this->actingAs($admin)
        ->from(route('ledger.index', 'borrowed'))
        ->putJson(route('ledger.update', ['borrowed', $entry->id]), [
            'entry_date' => '2026-08-04',
            'party_name' => 'Two Row Test',
            'total_amount' => '150',
            'currency' => 'AED',
            'paid_amount' => 0,
            'details' => [
                ['detail_date' => '2026-08-04', 'description' => 'row1', 'amount' => '100', 'returned_amount' => 0],
                ['detail_date' => '2026-08-05', 'description' => 'row2', 'amount' => '50', 'returned_amount' => ''],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ledger.index', 'borrowed'));

    expect($entry->fresh()->details)->toHaveCount(2);
});
