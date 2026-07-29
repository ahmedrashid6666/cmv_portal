<?php

use App\Enums\Role;
use App\Models\FinalCalculation;
use App\Models\User;

beforeEach(fn () => $this->actor = User::factory()->role(Role::ACCOUNTANT)->create());

$payload = fn (string $date = '2026-07-01') => [
    'calc_date' => $date,
    'data' => [
        'omr_rate' => 9.5238,
        'rows' => [
            ['key' => 'dws_bal', 'label' => 'DAILY WORK SHEET BAL', 'group' => 'top', 'amount' => 14345],
            ['key' => 'borrowed', 'label' => 'BORROWED CASH', 'group' => 'top', 'amount' => 71955],
            ['key' => 'daily_credit', 'label' => 'DAILY CREDIT TOTAL', 'group' => 'top', 'debt_exp' => 39329, 'cash_aed' => 15375],
            ['key' => 'bank_cdr', 'label' => 'CDR ACCOUNT', 'group' => 'banks', 'ac_balance' => 3612, 'currency' => 'OMR'],
            ['key' => 'exp_rak', 'label' => 'RAK A/C EXP', 'group' => 'other', 'cash_omr' => 250, 'manual' => true],
            ['key' => 'salary', 'label' => 'SALARY', 'group' => 'other', 'ac_balance' => 2000, 'manual' => true],
            ['key' => 'banks_rest', 'label' => 'OTHER BANKS', 'group' => 'banks', 'ac_balance' => 23537],
        ],
    ],
    'remarks' => 'July close',
];

it('shows the page with live-computed defaults when no snapshot exists', function () {
    $this->actingAs($this->actor)->get(route('final-calc.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('data.rows')->has('totals.liquid_cash')->where('saved', false));
});

it('saves a snapshot and stores the computed totals', function () use ($payload) {
    $this->actingAs($this->actor)->post(route('final-calc.store'), $payload())->assertRedirect();

    $c = FinalCalculation::first();
    expect((float) $c->total_amount)->toBe(86300.0)
        ->and((float) $c->total_ac_balance)->toBe(29149.0)   // 3612 + 2000 + 23537
        ->and((float) $c->total_debt_exp)->toBe(39329.0)
        ->and((float) $c->liquid_cash)->toBe(17822.0)
        ->and((float) $c->cash_counted)->toBe(17755.95)
        ->and((float) $c->cash_extra)->toBe(-66.05)
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
        ->assertInertia(fn ($p) => $p->where('saved', true)->where('totals.liquid_cash', fn ($v) => (float) $v === 17822.0));
});

it('forbids read-only users from saving', function () use ($payload) {
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('final-calc.store'), $payload())
        ->assertForbidden();
});

it('exports a snapshot PDF', function () {
    $c = FinalCalculation::create([
        'calc_date' => '2026-07-01',
        'data' => ['omr_rate' => 9.5238, 'rows' => [['key' => 'a', 'label' => 'X', 'group' => 'top', 'amount' => 100]]],
        'total_amount' => 100, 'liquid_cash' => 100, 'cash_counted' => 0, 'cash_extra' => -100,
    ]);

    $this->actingAs($this->actor)->get(route('final-calc.pdf', $c))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});
