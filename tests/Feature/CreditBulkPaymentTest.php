<?php

use App\Enums\Role;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->actor = User::factory()->role(Role::ACCOUNTANT)->create();
    $this->method = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->customer = Customer::factory()->create();
});

function creditSale(string $date, float $creditAmount, string $invoice): Transaction
{
    $t = Transaction::create([
        'transaction_date' => $date, 'invoice_no' => $invoice, 'customer_id' => test()->customer->id,
        'payment_method_id' => test()->method->id, 'customs_fees' => 0, 'gov_fees' => 0,
        'profit' => $creditAmount, 'vat_rate' => 0, 'credit_amount' => $creditAmount,
    ]);
    $t->recomputeTotals();

    return $t;
}

it('distributes a bulk credit payment FIFO across the oldest invoices first', function () {
    $a = creditSale('2026-07-01', 5000, 'INV-A'); // oldest
    $b = creditSale('2026-07-05', 5000, 'INV-B');
    $c = creditSale('2026-07-10', 5000, 'INV-C'); // newest

    $this->actingAs($this->actor)->post(route('credits.bulk-store'), [
        'mode' => 'fifo',
        'amount' => 8000,
        'payment_date' => '2026-07-15',
        'payment_method_id' => $this->method->id,
        'transaction_ids' => [$a->id, $b->id, $c->id],
    ])->assertRedirect();

    // 8000 → 5000 to A (fully paid), 3000 to B (partial), 0 to C
    expect((float) $a->fresh()->creditOutstanding())->toBe(0.0)
        ->and((float) $b->fresh()->creditOutstanding())->toBe(2000.0)
        ->and((float) $c->fresh()->creditOutstanding())->toBe(5000.0);

    expect(CreditPayment::count())->toBe(2)
        ->and(CreditPayment::where('transaction_id', $a->id)->first()->payment_date->format('Y-m-d'))->toBe('2026-07-15');
});

it('applies manual allocations per invoice', function () {
    $a = creditSale('2026-07-01', 5000, 'INV-A');
    $b = creditSale('2026-07-05', 5000, 'INV-B');

    $this->actingAs($this->actor)->post(route('credits.bulk-store'), [
        'mode' => 'manual',
        'payment_date' => '2026-07-15',
        'payment_method_id' => $this->method->id,
        'transaction_ids' => [$a->id, $b->id],
        'allocations' => [$a->id => 2000, $b->id => 5000],
    ])->assertRedirect();

    expect((float) $a->fresh()->creditOutstanding())->toBe(3000.0)
        ->and((float) $b->fresh()->creditOutstanding())->toBe(0.0);
});

it('rejects a manual allocation that exceeds an invoice balance', function () {
    $a = creditSale('2026-07-01', 5000, 'INV-A');

    $this->actingAs($this->actor)->from(route('credits.index'))
        ->post(route('credits.bulk-store'), [
            'mode' => 'manual',
            'payment_date' => '2026-07-15',
            'payment_method_id' => $this->method->id,
            'transaction_ids' => [$a->id],
            'allocations' => [$a->id => 9000],
        ])->assertSessionHasErrors('bulk');

    expect((float) $a->fresh()->creditOutstanding())->toBe(5000.0);
});

it('silently skips invoices with no credit or nothing outstanding', function () {
    $paid = creditSale('2026-07-01', 1000, 'INV-PAID');
    CreditPayment::create(['transaction_id' => $paid->id, 'payment_date' => '2026-07-02', 'amount' => 1000, 'payment_method_id' => $this->method->id]);
    $cash = Transaction::factory()->create(['customer_id' => $this->customer->id, 'payment_method_id' => $this->method->id, 'credit_amount' => 0]);
    $open = creditSale('2026-07-03', 2000, 'INV-OPEN');

    $this->actingAs($this->actor)->post(route('credits.bulk-store'), [
        'mode' => 'fifo',
        'amount' => 2000,
        'payment_date' => '2026-07-15',
        'payment_method_id' => $this->method->id,
        'transaction_ids' => [$paid->id, $cash->id, $open->id],
    ])->assertRedirect();

    expect((float) $open->fresh()->creditOutstanding())->toBe(0.0)
        ->and(CreditPayment::where('transaction_id', $cash->id)->count())->toBe(0);
});

it('requires a bank when settled via a bank-type method', function () {
    $bankMethod = PaymentMethod::create(['name' => 'Bank Transfer', 'type' => 'bank']);
    $a = creditSale('2026-07-01', 1000, 'INV-A');

    $this->actingAs($this->actor)->post(route('credits.bulk-store'), [
        'mode' => 'fifo', 'amount' => 500, 'payment_date' => '2026-07-15',
        'payment_method_id' => $bankMethod->id, 'transaction_ids' => [$a->id],
    ])->assertSessionHasErrors('bank_id');
});

it('forbids a read-only user', function () {
    $a = creditSale('2026-07-01', 5000, 'INV-A');
    $this->actingAs(User::factory()->role(Role::READ_ONLY)->create())
        ->post(route('credits.bulk-store'), [
            'mode' => 'fifo', 'amount' => 100, 'payment_date' => '2026-07-15',
            'payment_method_id' => $this->method->id, 'transaction_ids' => [$a->id],
        ])->assertForbidden();
});
