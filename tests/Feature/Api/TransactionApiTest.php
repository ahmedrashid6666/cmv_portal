<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $this->method = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
});

function apiPayload(): array
{
    return [
        'transaction_date' => '2026-07-01',
        'invoice_no' => '56732',
        'customer_id' => test()->customer->id,
        'payment_method_id' => test()->method->id,
        'customs_fees' => 295, 'gov_fees' => 0, 'other_amount' => 0, 'profit' => 50, 'vat_rate' => 0,
        'commissions' => [['amount' => 25, 'type' => 'charged_to_customer']],
    ];
}

it('issues a token via login and creates a transaction', function () {
    $user = User::factory()->role(Role::ACCOUNTANT)->create(['password' => bcrypt('secret123')]);

    $token = $this->postJson('/api/login', [
        'email' => $user->email, 'password' => 'secret123', 'device_name' => 'flutter',
    ])->assertOk()->json('token');

    expect($token)->toBeString()->not->toBeEmpty();

    $this->withToken($token)->postJson('/api/transactions', apiPayload())
        ->assertCreated()
        ->assertJsonPath('data.grand_total', 370)
        ->assertJsonPath('data.total_amount', 345);

    expect(Transaction::count())->toBe(1);
});

it('rejects unauthenticated requests', function () {
    $this->postJson('/api/transactions', apiPayload())->assertUnauthorized();
});

it('forbids a read-only token from writing', function () {
    Sanctum::actingAs(User::factory()->role(Role::READ_ONLY)->create());

    $this->postJson('/api/transactions', apiPayload())->assertForbidden();
});

it('lists transactions for an authenticated token', function () {
    Transaction::factory()->create();
    Sanctum::actingAs(User::factory()->role(Role::READ_ONLY)->create());

    $this->getJson('/api/transactions')->assertOk()->assertJsonStructure(['data' => [['id', 'grand_total']]]);
});
