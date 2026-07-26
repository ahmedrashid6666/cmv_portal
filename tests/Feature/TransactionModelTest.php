<?php

use App\Models\CreditPayment;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\TransactionExpense;

it('creates a transaction with expenses, commissions and a credit payment', function () {
    $t = Transaction::factory()->create();

    TransactionExpense::factory()->count(2)->create(['transaction_id' => $t->id]);
    TransactionCommission::factory()->count(2)->create(['transaction_id' => $t->id]);
    CreditPayment::factory()->create(['transaction_id' => $t->id]);

    $t->refresh();
    expect($t->expenses)->toHaveCount(2)
        ->and($t->commissions)->toHaveCount(2)
        ->and($t->creditPayments)->toHaveCount(1)
        ->and($t->customer)->not->toBeNull()
        ->and($t->paymentMethod)->not->toBeNull();
});

it('cascade-deletes children when the transaction is force-deleted', function () {
    $t = Transaction::factory()->create();
    TransactionExpense::factory()->create(['transaction_id' => $t->id]);
    TransactionCommission::factory()->create(['transaction_id' => $t->id]);

    $t->forceDelete();

    expect(TransactionExpense::where('transaction_id', $t->id)->count())->toBe(0)
        ->and(TransactionCommission::where('transaction_id', $t->id)->count())->toBe(0);
});
