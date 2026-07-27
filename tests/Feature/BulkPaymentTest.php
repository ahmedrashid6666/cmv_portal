<?php

use App\Enums\Role;
use App\Models\LedgerEntry;
use App\Models\LedgerPayment;
use App\Models\PaymentMethod;
use App\Models\User;

beforeEach(function () {
    $this->actor = User::factory()->role(Role::ACCOUNTANT)->create();
    $this->method = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
});

function credit(string $date, float $total, float $paid = 0, string $party = 'ESQUBE', string $ref = 'JRY'): LedgerEntry
{
    return LedgerEntry::create([
        'type' => 'daily_credit', 'entry_date' => $date, 'party_name' => $party, 'reference' => $ref,
        'total_amount' => $total, 'paid_amount' => $paid,
    ]);
}

it('distributes a bulk amount FIFO across the oldest entries first', function () {
    $a = credit('2026-07-01', 5000);   // oldest
    $b = credit('2026-07-05', 5000);
    $c = credit('2026-07-10', 5000);   // newest

    $this->actingAs($this->actor)->post(route('bulk.store', 'daily-credit'), [
        'mode' => 'fifo',
        'amount' => 8000,
        'payment_date' => '2026-07-15',
        'payment_method_id' => $this->method->id,
        'entry_ids' => [$a->id, $b->id, $c->id],
    ])->assertRedirect(route('ledger.index', 'daily-credit'));

    // 8000 → 5000 to A (settled), 3000 to B (partial), 0 to C
    expect((float) $a->fresh()->paid_amount)->toBe(5000.0)->and($a->fresh()->status)->toBe('returned');
    expect((float) $b->fresh()->paid_amount)->toBe(3000.0)->and($b->fresh()->status)->toBe('partial');
    expect($c->fresh()->status)->toBe('pending');

    // payment records written with the given date
    expect(LedgerPayment::count())->toBe(2)
        ->and(LedgerPayment::where('ledger_entry_id', $a->id)->first()->payment_date->format('Y-m-d'))->toBe('2026-07-15');
    expect($a->fresh()->return_date->format('Y-m-d'))->toBe('2026-07-15');
});

it('applies manual allocations per entry', function () {
    $a = credit('2026-07-01', 5000);
    $b = credit('2026-07-05', 5000);

    $this->actingAs($this->actor)->post(route('bulk.store', 'daily-credit'), [
        'mode' => 'manual',
        'payment_date' => '2026-07-15',
        'entry_ids' => [$a->id, $b->id],
        'allocations' => [$a->id => 2000, $b->id => 5000],
    ])->assertRedirect();

    expect((float) $a->fresh()->paid_amount)->toBe(2000.0)->and($a->fresh()->status)->toBe('partial');
    expect((float) $b->fresh()->paid_amount)->toBe(5000.0)->and($b->fresh()->status)->toBe('returned');
});

it('rejects a manual allocation that exceeds an entry balance', function () {
    $a = credit('2026-07-01', 5000);

    $this->actingAs($this->actor)->from(route('bulk.index', 'daily-credit'))
        ->post(route('bulk.store', 'daily-credit'), [
            'mode' => 'manual',
            'payment_date' => '2026-07-15',
            'entry_ids' => [$a->id],
            'allocations' => [$a->id => 9000],
        ])->assertSessionHasErrors('bulk');

    expect((float) $a->fresh()->paid_amount)->toBe(0.0);
});

it('only searches open entries of the right type', function () {
    credit('2026-07-01', 5000, 5000, 'ESQUBE');           // returned → excluded
    credit('2026-07-02', 3000, 0, 'ESQUBE');              // open → included
    LedgerEntry::factory()->borrowed()->create(['party_name' => 'ESQUBE', 'total_amount' => 1000]);

    $this->actingAs($this->actor)->get(route('bulk.index', ['daily-credit', 'search' => 'ESQUBE']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('entries', fn ($e) => count($e) === 1));
});

it('forbids a read-only user', function () {
    $a = credit('2026-07-01', 5000);
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('bulk.store', 'daily-credit'), ['mode' => 'fifo', 'amount' => 100, 'payment_date' => '2026-07-15', 'entry_ids' => [$a->id]])
        ->assertForbidden();
});
