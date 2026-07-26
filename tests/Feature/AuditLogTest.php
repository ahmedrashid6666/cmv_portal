<?php

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->customer = Customer::factory()->create();
});

function auditTx(array $o = []): Transaction
{
    return Transaction::create(array_merge([
        'transaction_date' => '2026-07-01',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->cash->id,
        'customs_fees' => 245, 'gov_fees' => 0, 'profit' => 35, 'vat_rate' => 0,
    ], $o));
}

it('logs creation of a transaction', function () {
    $t = auditTx();

    $log = ActivityLog::where('auditable_type', Transaction::class)->where('auditable_id', $t->id)->where('action', 'created')->first();
    expect($log)->not->toBeNull()
        ->and($log->label)->toContain('Invoice');
});

it('logs updates with an old->new diff, excluding derived fields', function () {
    $t = auditTx();
    $t->update(['profit' => 60]);

    $log = ActivityLog::where('auditable_id', $t->id)->where('action', 'updated')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->changes)->toHaveKey('profit')
        ->and((float) $log->changes['profit'][1])->toBe(60.0)
        ->and($log->changes)->not->toHaveKey('grand_total'); // derived excluded
});

it('logs deletion', function () {
    $t = auditTx();
    $t->delete();

    expect(ActivityLog::where('auditable_id', $t->id)->where('action', 'deleted')->exists())->toBeTrue();
});

it('shows the activity log to a super admin only', function () {
    auditTx();

    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->get(route('activity.index'))->assertOk();

    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('activity.index'))->assertForbidden();
});
