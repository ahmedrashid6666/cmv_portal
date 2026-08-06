<?php

use App\Enums\Role;
use App\Models\CashCount;
use App\Models\FinalCalculation;
use App\Models\User;

beforeEach(fn () => $this->actor = User::factory()->role(Role::ACCOUNTANT)->create());

$payload = fn (string $date = '2026-07-01') => [
    'calc_date' => $date,
    'data' => [
        'opening_balance' => 64061, 'total_income' => 15793, 'customs_gov_fees' => 11688,
        'credit_unpaid' => 8850, 'office_expenses' => 2434,
        'borrowed_amount' => 89700, 'daily_credit_pending' => 58069,
        'bank_ac_balance' => 56684, 'cdr_ac_balance' => 19927,
        'aed_counted' => 11000, 'omr_counted' => 0, 'omr_rate' => 9.5238,
    ],
    'remarks' => 'July close',
];

it('shows the page with live-computed defaults when no snapshot exists', function () {
    $this->actingAs($this->actor)->get(route('final-calc.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('data.opening_balance')->has('totals.total_cash_balance')
            ->has('denominations')->where('saved', false));
});

it('saves a snapshot and stores the computed totals', function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();

    $c = FinalCalculation::first();
    expect((float) $c->total_amount)->toBe(56882.0)
        ->and((float) $c->liquid_cash)->toBe(11902.0)   // Total Cash Balance In Hand
        ->and((float) $c->cash_counted)->toBe(11000.0)
        ->and((float) $c->cash_extra)->toBe(-902.0)      // 11000 - 11902
        ->and($c->remarks)->toBe('July close');
});

it('upserts one snapshot per date', function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();
    expect(FinalCalculation::count())->toBe(1);
});

it('reopens a saved date with its frozen figures', function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();

    $this->actingAs($this->actor)->get(route('final-calc.index', ['date' => '2026-07-01']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('saved', true)->where('totals.total_cash_balance', fn ($v) => (float) $v === 11902.0));
});

it("overlays the frozen snapshot's counted cash with a cash count saved afterwards", function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();

    CashCount::create(['count_date' => '2026-07-01', 'lines' => ['AED' => [], 'OMR' => []], 'total_aed' => 12500, 'total_omr' => 0]);

    $this->actingAs($this->actor)->get(route('final-calc.index', ['date' => '2026-07-01']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('data.aed_counted', 12500.0));
});

it('passes the full CashCount fields through so the embedded widget round-trips extras/remarks', function () {
    CashCount::create([
        'count_date' => '2026-07-01',
        'lines' => ['AED' => ['1000' => 2], 'OMR' => []],
        'extras' => ['AED' => [['label' => 'Petty cash out', 'amount' => 50]], 'OMR' => []],
        'bundles' => ['AED' => [], 'OMR' => []],
        'remarks' => 'Counted twice to confirm',
        'total_aed' => 2000, 'total_omr' => 0,
    ]);

    $this->actingAs($this->actor)->get(route('final-calc.index', ['date' => '2026-07-01']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('count.extras.AED.0.label', 'Petty cash out')
            ->where('count.remarks', 'Counted twice to confirm'));
});

it('forbids read-only users from saving', function () use ($payload) {
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('final-calc.store'), $payload())
        ->assertForbidden();
});

it('exports a snapshot PDF', function () {
    $c = FinalCalculation::create([
        'calc_date' => '2026-07-01',
        'data' => ['opening_balance' => 100, 'omr_rate' => 9.5238],
        'total_amount' => 100, 'liquid_cash' => 100, 'cash_counted' => 0, 'cash_extra' => -100,
    ]);

    $this->actingAs($this->actor)->get(route('final-calc.pdf', $c))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});
