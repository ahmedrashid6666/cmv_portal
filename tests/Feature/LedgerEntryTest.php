<?php

use App\Enums\Role;
use App\Models\LedgerEntry;
use App\Models\User;

beforeEach(fn () => $this->actor = User::factory()->role(Role::ACCOUNTANT)->create());

function creditPayload(array $o = []): array
{
    return array_merge([
        'entry_date' => '2026-07-01',
        'party_name' => 'ESQUBE INDUSTRIES',
        'reference' => 'JRY',
        'vehicle_number' => 'DXB-123',
        'total_amount' => 10000,
        'paid_amount' => 0,
    ], $o);
}

it('auto-calculates balance and pending status when nothing is paid', function () {
    $e = LedgerEntry::create(creditPayload(['type' => 'daily_credit']));

    expect((float) $e->balance_amount)->toBe(10000.0)
        ->and($e->status)->toBe('pending')
        ->and($e->return_date)->toBeNull();
});

it('marks partial when part is paid (10000 - 4000 = 6000)', function () {
    $e = LedgerEntry::create(creditPayload(['type' => 'daily_credit', 'paid_amount' => 4000]));

    expect((float) $e->balance_amount)->toBe(6000.0)
        ->and($e->status)->toBe('partial')
        ->and($e->return_date)->toBeNull();
});

it('marks returned and records the return date when fully paid', function () {
    $e = LedgerEntry::create(creditPayload(['type' => 'daily_credit', 'paid_amount' => 10000]));

    expect((float) $e->balance_amount)->toBe(0.0)
        ->and($e->status)->toBe('returned')
        ->and($e->return_date)->not->toBeNull();
});

it('clears the return date if a returned entry is edited back to partial', function () {
    $e = LedgerEntry::create(creditPayload(['type' => 'daily_credit', 'paid_amount' => 10000]));
    expect($e->return_date)->not->toBeNull();

    $e->update(['paid_amount' => 3000]);
    expect($e->status)->toBe('partial')
        ->and($e->return_date)->toBeNull();
});

it('lets an accountant create a daily-credit entry via the module', function () {
    $this->actingAs($this->actor)
        ->post(route('ledger.store', 'daily-credit'), creditPayload(['paid_amount' => 4000]))
        ->assertRedirect();

    $e = LedgerEntry::first();
    expect($e->type)->toBe('daily_credit')
        ->and((float) $e->balance_amount)->toBe(6000.0)
        ->and($e->status)->toBe('partial');
});

it('keeps borrowed and daily-credit entries separate', function () {
    LedgerEntry::factory()->create(['party_name' => 'A']);           // daily_credit
    LedgerEntry::factory()->borrowed()->create(['party_name' => 'B']); // borrowed

    $this->actingAs($this->actor)->get(route('ledger.index', 'borrowed'))->assertOk();
    expect(LedgerEntry::ofType('borrowed')->count())->toBe(1)
        ->and(LedgerEntry::ofType('daily_credit')->count())->toBe(1);
});

it('forbids a read-only user from creating entries', function () {
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('ledger.store', 'daily-credit'), creditPayload())
        ->assertForbidden();
});

it('exports a ledger report as pdf and xlsx', function () {
    LedgerEntry::factory()->create();

    $this->actingAs($this->actor)->get(route('ledger.export', ['daily-credit', 'format' => 'xlsx']))
        ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->actingAs($this->actor)->get(route('ledger.export', ['daily-credit', 'format' => 'pdf']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});
