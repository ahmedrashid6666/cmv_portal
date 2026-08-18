<?php

use App\Enums\Role;
use App\Models\CreditPayment;
use App\Models\OfficeExpense;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionCalculator;
use Database\Seeders\DefaultDataSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The books it produces
|--------------------------------------------------------------------------
*/

it('seeds a demo set of books', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect(Transaction::count())->toBe(25)
        ->and(OfficeExpense::count())->toBeGreaterThan(0)
        ->and(CreditPayment::count())->toBeGreaterThan(0);
});

it('produces totals that reconcile with the calculation engine', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $calc = app(TransactionCalculator::class);

    // Demo data whose totals do not add up is worse than no demo data — a
    // prospect checking the arithmetic is exactly the prospect worth winning.
    Transaction::with(['expenses', 'commissions'])->get()->each(function ($t) use ($calc) {
        $commissions = $t->commissions->map(fn ($c) => ['type' => $c->type, 'amount' => $c->amount])->all();
        $expenses = $t->expenses->map(fn ($e) => ['amount' => $e->amount])->all();

        expect((float) $t->grand_total)
            ->toBe((float) $calc->grandTotal($t->total_amount, $commissions))
            ->and((float) $t->net_profit)
            ->toBe((float) $calc->netProfit(
                $t->profit,
                $calc->totalExpenses($expenses),
                $calc->commissionPayable($commissions),
            ));
    });
});

it('never books a credit amount larger than the invoice', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    Transaction::all()->each(
        fn ($t) => expect((float) $t->credit_amount)->toBeLessThanOrEqual((float) $t->grand_total)
    );
});

it('leaves a spread of paid, partial and unpaid invoices', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $statuses = Transaction::with('creditPayments')->get()
        ->map(fn ($t) => $t->invoiceStatus())
        ->unique()
        ->values()
        ->all();

    // A receivables screen showing only one status demos nothing.
    expect($statuses)->toContain('paid')
        ->and(count($statuses))->toBeGreaterThan(1);
});

it('spreads the books across more than one month', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $months = Transaction::pluck('transaction_date')
        ->map(fn ($d) => $d->format('Y-m'))
        ->unique();

    expect($months->count())->toBeGreaterThan(1);
});

/*
|--------------------------------------------------------------------------
| Refusing to run on real books
|--------------------------------------------------------------------------
*/

it('refuses to seed a database that already holds transactions', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $before = Transaction::count();

    expect(fn () => $this->seed(DemoDataSeeder::class))
        ->toThrow(RuntimeException::class, 'already contains transactions');

    expect(Transaction::count())->toBe($before);
});

it('wipes and reseeds only when the database is named explicitly', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $firstIds = Transaction::orderBy('id')->pluck('id')->all();

    putenv('DEMO_RESET='.DB::connection()->getDatabaseName());
    $this->seed(DemoDataSeeder::class);
    putenv('DEMO_RESET');

    expect(Transaction::count())->toBe(25)
        ->and(Transaction::orderBy('id')->pluck('id')->all())->not->toBe($firstIds);
});

it('ignores a reset naming some other database', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    putenv('DEMO_RESET=some_other_database');

    expect(fn () => $this->seed(DemoDataSeeder::class))
        ->toThrow(RuntimeException::class);

    putenv('DEMO_RESET');

    expect(Transaction::count())->toBe(25);
});

/*
|--------------------------------------------------------------------------
| The demo account
|--------------------------------------------------------------------------
*/

it('creates the demo user as an accountant, never an administrator', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $demo = User::where('email', config('demo.user_email'))->first();

    expect($demo)->not->toBeNull()
        ->and($demo->role)->toBe(Role::ACCOUNTANT);
});

it('keeps the demo user out of every administration page', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $demo = User::where('email', config('demo.user_email'))->first();

    // Hiding the menu is not enough; the pages themselves must refuse.
    foreach (['users.index', 'activity.index', 'custom-fields.index', 'bin.index', 'backup.index', 'settings.index'] as $route) {
        $this->actingAs($demo)->get(route($route))->assertForbidden();
    }
});

it('still lets the demo user do the things worth demonstrating', function () {
    $this->seed(DefaultDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $demo = User::where('email', config('demo.user_email'))->first();

    $this->actingAs($demo)->get(route('dashboard'))->assertOk();
    $this->actingAs($demo)->get(route('transactions.index'))->assertOk();
    $this->actingAs($demo)->get(route('reports.index'))->assertOk();
});
