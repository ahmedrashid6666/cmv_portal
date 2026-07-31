<?php

use App\Enums\Role;
use App\Models\CashCount;
use App\Models\User;

beforeEach(fn () => $this->actor = User::factory()->role(Role::ACCOUNTANT)->create());

it('saves a cash count and computes totals from denominations only (extras are reference only)', function () {
    $this->actingAs($this->actor)->post(route('cash-count.store'), [
        'count_date' => '2026-07-27',
        'lines' => [
            'AED' => ['1000' => 5, '100' => 3],                // 5000 + 300 = 5300
            'OMR' => ['50' => 4, '5' => 2, '0.1' => 3],        // 200 + 10 + 0.3 = 210.3
        ],
        'extras' => [
            'AED' => [['label' => 'BDL-1', 'amount' => 18900]],
            'OMR' => [['label' => 'MIX BDL', 'amount' => 1271.7]],
        ],
    ])->assertRedirect();

    $c = CashCount::first();
    expect((float) $c->total_aed)->toBe(5300.0)
        ->and((float) $c->total_omr)->toBe(210.3);
});

it('upserts one count per date', function () {
    $payload = ['count_date' => '2026-07-27', 'lines' => ['AED' => ['1000' => 1], 'OMR' => []]];
    $this->actingAs($this->actor)->post(route('cash-count.store'), $payload)->assertRedirect();
    $this->actingAs($this->actor)->post(route('cash-count.store'), ['count_date' => '2026-07-27', 'lines' => ['AED' => ['1000' => 2], 'OMR' => []]])->assertRedirect();

    expect(CashCount::count())->toBe(1)
        ->and((float) CashCount::first()->total_aed)->toBe(2000.0);
});

it('shows the cash count page with the expected balance', function () {
    $this->actingAs($this->actor)->get(route('cash-count.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('expectedAed')->has('denominations'));
});

it('exports a cash count PDF', function () {
    $c = CashCount::create(['count_date' => '2026-07-27', 'lines' => ['AED' => ['1000' => 1], 'OMR' => []], 'total_aed' => 1000, 'total_omr' => 0, 'expected_aed' => 900]);

    $this->actingAs($this->actor)->get(route('cash-count.pdf', $c))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('forbids read-only users from saving', function () {
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('cash-count.store'), ['count_date' => '2026-07-27', 'lines' => ['AED' => []]])
        ->assertForbidden();
});
