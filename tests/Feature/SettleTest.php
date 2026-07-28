<?php

use App\Enums\Role;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->actor = User::factory()->role(Role::ACCOUNTANT)->create();
    $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->customer = Customer::factory()->create();
});

it('edits a ledger entry paid amount from the status dialog', function () {
    $e = LedgerEntry::create(['type' => 'daily_credit', 'entry_date' => '2026-07-01', 'party_name' => 'ESQUBE', 'total_amount' => 10000, 'paid_amount' => 0]);

    $this->actingAs($this->actor)->put(route('ledger.settle', ['daily-credit', $e->id]), ['paid_amount' => 4000])
        ->assertRedirect();

    $e->refresh();
    expect((float) $e->paid_amount)->toBe(4000.0)
        ->and((float) $e->balance_amount)->toBe(6000.0)
        ->and($e->status)->toBe('partial');
});

it('reverses a credit payment to edit the paid amount', function () {
    $t = Transaction::create([
        'transaction_date' => '2026-07-01', 'invoice_no' => '56728', 'customer_id' => $this->customer->id,
        'payment_method_id' => $this->cash->id, 'customs_fees' => 100, 'gov_fees' => 0, 'profit' => 10, 'vat_rate' => 0, 'credit_amount' => 110,
    ]);
    $p = CreditPayment::create(['transaction_id' => $t->id, 'payment_date' => '2026-07-05', 'amount' => 40, 'payment_method_id' => $this->cash->id]);
    expect((float) $t->creditOutstanding())->toBe(70.0);

    $this->actingAs($this->actor)->delete(route('credits.payment.destroy', $p->id))->assertRedirect();

    expect((float) $t->fresh()->creditOutstanding())->toBe(110.0)
        ->and(CreditPayment::count())->toBe(0);
});

it('forbids read-only users from settling', function () {
    $e = LedgerEntry::create(['type' => 'daily_credit', 'entry_date' => '2026-07-01', 'party_name' => 'X', 'total_amount' => 100, 'paid_amount' => 0]);
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->put(route('ledger.settle', ['daily-credit', $e->id]), ['paid_amount' => 50])
        ->assertForbidden();
});
