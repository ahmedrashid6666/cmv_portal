<?php

use App\Enums\Role;
use App\Models\CashCount;
use App\Models\User;

beforeEach(fn () => $this->actor = User::factory()->role(Role::ACCOUNTANT)->create());

it('saves a cash count and computes totals from denominations plus bundles (extras are reference only)', function () {
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
        'bundles' => [
            'AED' => [['label' => 'Bundle-1', 'amount' => 9000]],  // 5300 + 9000 = 14300
            'OMR' => [['label' => 'Bundle-1', 'amount' => 500]],   // 210.3 + 500 = 710.3
        ],
    ])->assertRedirect();

    $c = CashCount::first();
    expect((float) $c->total_aed)->toBe(14300.0)
        ->and((float) $c->total_omr)->toBe(710.3);
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

it('computes the slip Balance Amount as net IN minus OUT, alternating by index', function () {
    $extras = [
        'AED' => [
            ['label' => 'OLD BAL', 'amount' => 24685],   // IN  (index 0)
            ['label' => 'car wash', 'amount' => 20],      // OUT (index 1)
            ['label' => '', 'amount' => 0],                // IN  (index 2, blank — still counted as 0)
            ['label' => 'recharge', 'amount' => 20],       // OUT (index 3)
        ],
    ];

    expect(App\Models\CashCount::extrasBalanceFor('AED', $extras))->toBe(24645.0)   // 24685 - 20 - 20
        ->and(App\Models\CashCount::extrasBalanceFor('OMR', $extras))->toBe(0.0);
});

it("includes each date's slip Balance Amount in the Recent Counts history", function () {
    CashCount::create([
        'count_date' => '2026-07-27',
        'lines' => ['AED' => [], 'OMR' => []],
        'extras' => [
            'AED' => [['label' => 'IN-1', 'amount' => 500], ['label' => 'OUT-1', 'amount' => 200]],
            'OMR' => [],
        ],
        'total_aed' => 0,
        'total_omr' => 0,
    ]);

    $this->actingAs($this->actor)->get(route('cash-count.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('history.0.balance_aed', fn ($v) => (float) $v === 300.0)
            ->where('history.0.balance_omr', fn ($v) => (float) $v === 0.0));
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
