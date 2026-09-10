<?php

use App\Enums\Role;
use App\Models\CompanyBankDetail;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->actor = User::factory()->role(Role::ACCOUNTANT)->create();
    $this->method = PaymentMethod::create(['name' => 'Cash', 'type' => 'cash']);
    $this->customer = Customer::create(['name' => 'ESQUBE INDUSTRIES LLC', 'contact' => '050 123 4567', 'email' => 'a@esqube.com', 'address' => 'Dubai, UAE']);
});

function creditInvoice(int $customerId, string $date, float $creditAmount, ?string $invoice = null, ?string $refName = null): Transaction
{
    $refId = null;
    if ($refName) {
        $refId = Reference::create(['name' => $refName])->id;
    }

    $t = Transaction::create([
        'transaction_date' => $date, 'invoice_no' => $invoice, 'customer_id' => $customerId, 'reference_id' => $refId,
        'payment_method_id' => test()->method->id, 'customs_fees' => 0, 'gov_fees' => 0,
        'profit' => $creditAmount, 'vat_rate' => 0, 'credit_amount' => $creditAmount,
    ]);
    $t->recomputeTotals();

    return $t;
}

it('downloads an outstanding statement PDF for a customer', function () {
    creditInvoice($this->customer->id, '2026-08-01', 500, 'STMT-1', 'JRY');

    $this->actingAs($this->actor)
        ->get(route('credits.statement', $this->customer))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('downloads a statement even for a customer with nothing outstanding', function () {
    $this->actingAs($this->actor)
        ->get(route('credits.statement', $this->customer))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('rejects an unknown bank_id', function () {
    creditInvoice($this->customer->id, '2026-08-01', 500, 'STMT-1');

    $this->actingAs($this->actor)
        ->get(route('credits.statement', ['customer' => $this->customer->id, 'bank_id' => 999999]))
        ->assertSessionHasErrors('bank_id');
});

it('searches the outstanding credit list by reference', function () {
    creditInvoice($this->customer->id, '2026-08-01', 500, 'STMT-1', 'JRY');
    $other = Customer::create(['name' => 'OTHER LLC']);
    creditInvoice($other->id, '2026-08-01', 400, 'STMT-2', 'ZNY');

    $this->actingAs($this->actor)->get(route('credits.index', ['search' => 'JRY']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('outstanding', fn ($rows) => count($rows) === 1 && $rows[0]['invoice_no'] === 'STMT-1'));
});

it('downloads a combined statement for a single-reference, multi-customer filter (the "Company Name" branch)', function () {
    $esqube = $this->customer;
    $other = Customer::create(['name' => 'OTHER LLC']);
    creditInvoice($esqube->id, '2026-08-01', 500, 'STMT-1', 'ZNY');
    creditInvoice($other->id, '2026-08-02', 300, 'STMT-2', 'ZNY');

    $this->actingAs($this->actor)
        ->get(route('credits.statement.filtered', ['search' => 'ZNY']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('downloads a combined statement scoped to a date range (the "period" line)', function () {
    creditInvoice($this->customer->id, '2026-08-01', 500, 'STMT-1');

    $this->actingAs($this->actor)
        ->get(route('credits.statement.filtered', ['from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('downloads a combined statement with no filters at all (the generic "All Outstanding" branch)', function () {
    $other = Customer::create(['name' => 'OTHER LLC']);
    creditInvoice($this->customer->id, '2026-08-01', 500, 'STMT-1', 'JRY');
    creditInvoice($other->id, '2026-08-02', 300, 'STMT-2', 'ZNY');

    $this->actingAs($this->actor)
        ->get(route('credits.statement.filtered'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('respects the status filter on the combined statement', function () {
    $paid = creditInvoice($this->customer->id, '2026-08-01', 300, 'PAID-1');
    CreditPayment::create(['transaction_id' => $paid->id, 'payment_date' => '2026-08-02', 'amount' => 300, 'payment_method_id' => $this->method->id]);
    creditInvoice($this->customer->id, '2026-08-03', 200, 'OPEN-1');

    $this->actingAs($this->actor)
        ->get(route('credits.statement.filtered', ['status' => 'unpaid']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('lets an admin manage company bank details, keeping only one default', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();

    $this->actingAs($admin)->post(route('masters.store', 'company-bank-details'), [
        'bank_name' => 'ADCB', 'account_name' => 'CMV', 'account_number' => '111', 'is_default' => true,
    ])->assertRedirect();
    $first = CompanyBankDetail::where('bank_name', 'ADCB')->first();
    expect($first->is_default)->toBeTrue();

    $this->actingAs($admin)->post(route('masters.store', 'company-bank-details'), [
        'bank_name' => 'Emirates NBD', 'account_name' => 'CMV', 'account_number' => '222', 'is_default' => true,
    ])->assertRedirect();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(CompanyBankDetail::where('bank_name', 'Emirates NBD')->first()->is_default)->toBeTrue()
        ->and(CompanyBankDetail::where('is_default', true)->count())->toBe(1);
});

it('saves a customer email and address through the Customers master', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();

    $this->actingAs($admin)->put(route('masters.update', ['customers', $this->customer->id]), [
        'name' => $this->customer->name, 'contact' => $this->customer->contact,
        'email' => 'new@esqube.com', 'address' => 'Jebel Ali, Dubai', 'opening_balance' => 0,
    ])->assertRedirect();

    expect($this->customer->fresh()->email)->toBe('new@esqube.com')
        ->and($this->customer->fresh()->address)->toBe('Jebel Ali, Dubai');
});

describe('CompanyBankDetail::resolveFor', function () {
    it('returns the explicitly requested bank', function () {
        CompanyBankDetail::create(['bank_name' => 'Emirates NBD', 'account_name' => 'CMV', 'account_number' => '222', 'is_default' => true]);
        $chosen = CompanyBankDetail::create(['bank_name' => 'ADCB', 'account_name' => 'CMV', 'account_number' => '111']);

        $resolved = CompanyBankDetail::resolveFor(Request::create('/', 'GET', ['bank_id' => $chosen->id]));

        expect($resolved?->id)->toBe($chosen->id);
    });

    it('returns null when bank_id is explicitly sent empty', function () {
        CompanyBankDetail::create(['bank_name' => 'Emirates NBD', 'account_name' => 'CMV', 'account_number' => '222', 'is_default' => true]);

        $resolved = CompanyBankDetail::resolveFor(Request::create('/', 'GET', ['bank_id' => '']));

        expect($resolved)->toBeNull();
    });

    it('falls back to the default bank when bank_id is absent entirely', function () {
        CompanyBankDetail::create(['bank_name' => 'ADCB', 'account_name' => 'CMV', 'account_number' => '111']);
        $default = CompanyBankDetail::create(['bank_name' => 'Emirates NBD', 'account_name' => 'CMV', 'account_number' => '222', 'is_default' => true]);

        $resolved = CompanyBankDetail::resolveFor(Request::create('/', 'GET'));

        expect($resolved?->id)->toBe($default->id);
    });
});
