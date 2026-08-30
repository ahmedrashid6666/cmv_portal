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

/** A borrowed entry with three detail rows, used by the per-entry export tests. */
function exportableEntry(): LedgerEntry
{
    $entry = LedgerEntry::create([
        'type' => 'borrowed', 'entry_date' => '2026-08-25', 'party_name' => 'JAWAD',
        'reference' => 'JWD', 'currency' => 'AED',
        'total_amount' => 9551, 'paid_amount' => 2500, 'balance_amount' => 7051, 'status' => 'partial',
    ]);
    $entry->details()->create(['detail_date' => '2026-08-04', 'description' => '', 'amount' => 6551, 'returned_amount' => 0]);
    $entry->details()->create(['detail_date' => '2026-08-06', 'description' => '', 'amount' => 0, 'returned_amount' => 2500]);
    $entry->details()->create(['detail_date' => '2026-08-07', 'description' => 'add cash', 'amount' => 3000, 'returned_amount' => 0]);

    return $entry;
}

it('exports one ledger entry as a workbook of its detail rows', function () {
    $entry = exportableEntry();

    $response = $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('ledger.entry-export', ['borrowed', $entry->id]));
    $response->assertOk();

    $file = tempnam(sys_get_temp_dir(), 'ledger').'.xlsx';
    file_put_contents($file, $response->streamedContent());
    $rows = collect(PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet()->toArray(null, true, false));
    unlink($file);

    expect($rows->first()[0])->toBe('Borrowed Amount — JAWAD')
        // header block carries the party details
        ->and($rows->contains(fn ($r) => $r[0] === 'Person Name' && $r[1] === 'JAWAD'))->toBeTrue()
        // the columns are named for this ledger type
        ->and($rows->contains(fn ($r) => $r[2] === 'Borrowed' && $r[3] === 'Returned'))->toBeTrue()
        // amounts are real numbers, so the sheet can sum them
        ->and($rows->contains(fn ($r) => $r[1] === 'add cash' && (float) $r[2] === 3000.0))->toBeTrue()
        ->and($rows->contains(fn ($r) => str_contains((string) $r[0], 'Total Borrowed: AED 9,551.00')))->toBeTrue();
});

it('exports one ledger entry as a PDF', function () {
    $entry = exportableEntry();

    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('ledger.entry-export', ['borrowed', $entry->id, 'format' => 'pdf']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('refuses to export an entry through the wrong ledger type', function () {
    $entry = exportableEntry();

    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('ledger.entry-export', ['daily-credit', $entry->id]))
        ->assertNotFound();
});

it('falls back to the entry totals when it has no detail rows', function () {
    $entry = LedgerEntry::create([
        'type' => 'daily_credit', 'entry_date' => '2026-08-25', 'party_name' => 'Legacy Row',
        'currency' => 'AED', 'total_amount' => 400, 'paid_amount' => 150, 'balance_amount' => 250, 'status' => 'partial',
        'remarks' => 'Saved before detail rows existed',
    ]);

    $response = $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('ledger.entry-export', ['daily-credit', $entry->id]));

    $file = tempnam(sys_get_temp_dir(), 'ledger').'.xlsx';
    file_put_contents($file, $response->streamedContent());
    $rows = collect(PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet()->toArray(null, true, false));
    unlink($file);

    expect($rows->contains(fn ($r) => $r[1] === 'Saved before detail rows existed' && (float) $r[2] === 400.0))->toBeTrue();
});
