<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Bank;
use App\Models\BankEntry;
use App\Models\CashCount;
use App\Models\CreditPayment;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\LedgerEntry;
use App\Models\OfficeExpense;
use App\Models\PaymentMethod;
use App\Models\PettyCashEntry;
use App\Models\Reference;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TransactionWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Plausible sample books for the sales demo.
 *
 * Transactions are written through TransactionWriter rather than inserted
 * directly, so VAT, grand totals and net profit are produced by the same engine
 * the app uses. Demo data with totals that do not reconcile is worse than none.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Refuses to touch a database that already holds transactions. To wipe and
 * reseed a demo instance, name the database explicitly:
 *
 *   DEMO_RESET=u925208630_shipaccdemo php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private const TRANSACTION_COUNT = 25;

    /** Spread over roughly a quarter so the monthly charts have a trend to show. */
    private const SPAN_DAYS = 90;

    public function run(): void
    {
        $this->guardAgainstRealBooks();

        // Fixed seed: same books on every run, so screenshots and demo scripts
        // stay valid.
        mt_srand(20260818);

        $user = $this->demoUser();
        $masters = $this->masters();

        Setting::put('vat_rate', 5);

        $transactions = $this->transactions($user, $masters);

        $this->creditPayments($transactions, $masters, $user);
        $this->officeExpenses($masters, $user);
        $this->bankEntries($masters, $user);
        $this->pettyCash($user);
        $this->ledgerEntries($user);
        $this->cashCounts($user);

        $this->command?->info(sprintf(
            'Demo books seeded: %d transactions across %d days.',
            count($transactions),
            self::SPAN_DAYS,
        ));
    }

    /**
     * Never let this run against a real installation.
     *
     * Keyed on "does this database already have transactions", which is true of
     * any live install and false of a fresh demo. Wiping requires naming the
     * database, so a mistyped command cannot destroy the wrong books.
     */
    private function guardAgainstRealBooks(): void
    {
        $database = DB::connection()->getDatabaseName();

        if (env('DEMO_RESET') === $database) {
            $this->wipe();

            return;
        }

        if (Transaction::withTrashed()->exists()) {
            throw new RuntimeException(
                "Refusing to seed demo data: the database [{$database}] already contains transactions. "
                ."If this really is a demo instance, re-run with DEMO_RESET={$database} to wipe and reseed it."
            );
        }
    }

    /** Clears the operational books, leaving masters, users and settings alone. */
    private function wipe(): void
    {
        // transactions cascade to their expenses, commissions and credit payments.
        foreach ([
            'final_calculations', 'cash_counts', 'petty_cash_entries', 'bank_entries',
            'office_expenses', 'ledger_payments', 'ledger_entry_details', 'ledger_entries',
            'transactions', 'activity_logs',
        ] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function demoUser(): User
    {
        // Accountant, never an administrator: the demo login hands this session
        // to anyone who can reach the public URL.
        return User::updateOrCreate(
            ['email' => config('demo.user_email', 'demo@harkcreation.com')],
            [
                'name' => 'Demo User',
                'password' => env('DEMO_USER_PASSWORD') ?: Str::random(32),
                'role' => Role::ACCOUNTANT->value,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function masters(): array
    {
        $customers = collect([
            ['name' => 'Al Manara Trading LLC', 'contact' => '+971 4 221 8890'],
            ['name' => 'Gulf Horizon General Trading', 'contact' => '+971 4 337 1204'],
            ['name' => 'Desert Falcon Logistics', 'contact' => '+971 6 544 7712'],
            ['name' => 'Emirates Steel Supplies', 'contact' => '+971 4 882 3390'],
            ['name' => 'Sahara Foodstuff LLC', 'contact' => '+971 4 269 5518'],
            ['name' => 'Blue Pearl Electronics', 'contact' => '+971 4 355 6621'],
            ['name' => 'Northern Star Machinery', 'contact' => '+971 6 767 4433'],
            ['name' => 'Oasis Building Materials', 'contact' => '+971 4 447 9010'],
        ])->map(fn ($c) => Customer::firstOrCreate(['name' => $c['name']], $c));

        $references = collect([
            ['name' => 'Rashid Al Balushi', 'company' => 'Rashid Clearing Services'],
            ['name' => 'Imran Sheikh', 'company' => 'Sheikh Cargo Agents'],
            ['name' => 'Vinod Menon', 'company' => 'Menon Freight'],
            ['name' => 'Hassan Kutty', 'company' => 'Kutty Clearing'],
        ])->map(fn ($r) => Reference::firstOrCreate(['name' => $r['name']], $r));

        $vehicles = collect(['DXB 41290', 'SHJ 77315', 'DXB 15884', 'AUH 60427', 'DXB 92016', 'SHJ 33471'])
            ->map(fn ($n) => Vehicle::firstOrCreate(['number' => $n]));

        $banks = collect([
            ['name' => 'Emirates NBD — Current', 'account_no' => '1015480921', 'opening_balance' => 42500, 'is_customs' => false],
            ['name' => 'Mashreq Business', 'account_no' => '0198234117', 'opening_balance' => 18750, 'is_customs' => false],
            ['name' => 'Customs Deposit Account', 'account_no' => '7781203355', 'opening_balance' => 30000, 'is_customs' => true],
        ])->map(fn ($b) => Bank::firstOrCreate(['name' => $b['name']], $b));

        return [
            'customers' => $customers,
            'references' => $references,
            'vehicles' => $vehicles,
            'banks' => $banks,
            'categories' => ExpenseCategory::pluck('id', 'name'),
            'methods' => PaymentMethod::pluck('id', 'name'),
            'methodTypes' => PaymentMethod::pluck('type', 'id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<int, Transaction>
     */
    private function transactions(User $user, array $m): array
    {
        $writer = app(TransactionWriter::class);
        $created = [];

        $cashId = $m['methods']['Cash'];
        $creditId = $m['methods']['Credit'];
        $bankIds = [$m['methods']['Bank'], $m['methods']['Online Transfer'], $m['methods']['Cheque']];

        $operatingBanks = $m['banks']->where('is_customs', false)->values();
        $expenseCategoryIds = $m['categories']->values()->all();

        for ($i = 0; $i < self::TRANSACTION_COUNT; $i++) {
            $date = $this->workingDay($i);

            // Payment mix: mostly settled at the counter, a healthy minority on
            // credit so the receivables screens have something to show.
            $roll = mt_rand(1, 100);
            if ($roll <= 40) {
                $methodId = $cashId;
            } elseif ($roll <= 72) {
                $methodId = $bankIds[mt_rand(0, count($bankIds) - 1)];
            } else {
                $methodId = $creditId;
            }

            $isBankPaid = ($m['methodTypes'][$methodId] ?? null) === 'bank';
            $bank = $operatingBanks[$i % $operatingBanks->count()];

            $expenses = [];
            foreach (range(0, mt_rand(0, 2)) as $n) {
                if ($n === 0 && mt_rand(1, 100) > 75) {
                    continue;
                }
                $expenses[] = [
                    'expense_category_id' => $expenseCategoryIds[mt_rand(0, count($expenseCategoryIds) - 1)],
                    'description' => null,
                    'amount' => mt_rand(25, 400),
                ];
            }

            $commissions = [];
            if (mt_rand(1, 100) <= 45) {
                $commissions[] = [
                    'label' => 'Clearing commission',
                    'amount' => mt_rand(100, 450),
                    'type' => 'charged_to_customer',
                ];
            }
            if (mt_rand(1, 100) <= 30) {
                $commissions[] = [
                    'label' => 'Agent share',
                    'amount' => mt_rand(75, 300),
                    'type' => 'paid_to_reference',
                    'reference_id' => $m['references']->random()->id,
                ];
            }

            $transaction = $writer->create([
                'transaction_date' => $date->toDateString(),
                'invoice_no' => sprintf('INV-%04d', 1000 + $i),
                'boe_no' => (string) mt_rand(20300000, 20399999),
                'customer_id' => $m['customers']->random()->id,
                'reference_id' => mt_rand(1, 100) <= 70 ? $m['references']->random()->id : null,
                'vehicle_number' => $m['vehicles']->random()->number,
                'customs_fees' => mt_rand(400, 6500),
                'gov_fees' => mt_rand(150, 900),
                'profit' => mt_rand(250, 1800),
                'vat_rate' => 5,
                'currency' => 'AED',
                'payment_method_id' => $methodId,
                'bank_id' => $isBankPaid ? $bank->id : null,
                'credit_amount' => 0,
                'expenses' => $expenses,
                'commissions' => $commissions,
                'created_by' => $user->id,
            ]);

            // Credit sales go on the books for their full value. Done after the
            // write because it is grand_total — computed by the engine — that is
            // owed, not any figure invented here.
            if ($methodId === $creditId) {
                $transaction->update(['credit_amount' => $transaction->grand_total]);
            }

            $created[] = $transaction->refresh();
        }

        return $created;
    }

    /**
     * Part-payments against some credit sales, so the receivables list shows a
     * mix of unpaid, partially paid and settled.
     *
     * @param  array<int, Transaction>  $transactions
     * @param  array<string, mixed>  $m
     */
    private function creditPayments(array $transactions, array $m, User $user): void
    {
        $credit = collect($transactions)->filter(fn ($t) => (float) $t->credit_amount > 0)->values();

        foreach ($credit as $i => $transaction) {
            // Leave every third one untouched as still fully outstanding.
            if ($i % 3 === 0) {
                continue;
            }

            $total = (float) $transaction->credit_amount;
            $settled = $i % 3 === 1;
            $amount = $settled ? $total : round($total * (mt_rand(30, 60) / 100), 2);

            CreditPayment::create([
                'transaction_id' => $transaction->id,
                'payment_date' => $transaction->transaction_date->copy()->addDays(mt_rand(5, 25))->toDateString(),
                'amount' => $amount,
                'payment_method_id' => $m['methods']['Cash'],
                'note' => $settled ? 'Settled in full' : 'Part payment received',
                'created_by' => $user->id,
            ]);
        }
    }

    /** @param  array<string, mixed>  $m */
    private function officeExpenses(array $m, User $user): void
    {
        $items = [
            ['Office rent — monthly share', 3500, 'Office Expenses'],
            ['Etisalat internet & phone', 610, 'Office Expenses'],
            ['Printer toner and paper', 285, 'Printing'],
            ['Salik top-up', 200, 'Salik'],
            ['Fuel — office vehicle', 340, 'Fuel'],
            ['Courier to Jebel Ali', 95, 'Courier Charges'],
            ['Typing centre charges', 150, 'Typing Charges'],
            ['Parking permits', 120, 'Parking'],
            ['Staff refreshments', 180, 'Miscellaneous'],
            ['ZAJEL document delivery', 75, 'ZAJEL Payment'],
        ];

        foreach ($items as $i => [$description, $amount, $category]) {
            OfficeExpense::create([
                'expense_date' => $this->workingDay($i * 2)->toDateString(),
                'expense_category_id' => $m['categories'][$category] ?? null,
                'description' => $description,
                'amount' => $amount,
                'currency' => 'AED',
                'payment_method_id' => $i % 3 === 0 ? $m['methods']['Bank'] : $m['methods']['Cash'],
                'bank_id' => $i % 3 === 0 ? $m['banks']->first()->id : null,
                'created_by' => $user->id,
            ]);
        }
    }

    /** @param  array<string, mixed>  $m */
    private function bankEntries(array $m, User $user): void
    {
        $rows = [
            ['Customer deposit — Al Manara', 'in', 12500],
            ['Customs duty transfer', 'out', 8400],
            ['Customer deposit — Gulf Horizon', 'in', 9750],
            ['Salary transfer', 'out', 14200],
            ['Customer deposit — Emirates Steel', 'in', 16800],
            ['Bank charges', 'out', 105],
            ['Cash deposit', 'in', 6000],
            ['Supplier payment', 'out', 4300],
        ];

        foreach ($rows as $i => [$item, $direction, $amount]) {
            BankEntry::create([
                'bank_id' => $m['banks'][$i % 2]->id,
                'entry_date' => $this->workingDay($i * 3)->toDateString(),
                'item' => $item,
                'direction' => $direction,
                'amount' => $amount,
                'created_by' => $user->id,
            ]);
        }
    }

    private function pettyCash(User $user): void
    {
        $rows = [
            ['Opening float', 2000, 0],
            ['Taxi — customs house', 0, 45],
            ['Water & pantry supplies', 0, 120],
            ['Photocopies', 0, 35],
            ['Cash top-up from bank', 1500, 0],
            ['Courier charges', 0, 60],
            ['Stationery', 0, 145],
            ['Parking coupons', 0, 80],
        ];

        foreach ($rows as $i => [$item, $in, $out]) {
            PettyCashEntry::create([
                'entry_date' => $this->workingDay($i * 4)->toDateString(),
                'item' => $item,
                'in_amount' => $in,
                'out_amount' => $out,
                'created_by' => $user->id,
            ]);
        }
    }

    private function ledgerEntries(User $user): void
    {
        $rows = [
            ['daily_credit', 'Al Manara Trading LLC', 4200, 4200],
            ['daily_credit', 'Sahara Foodstuff LLC', 2650, 1000],
            ['daily_credit', 'Blue Pearl Electronics', 1875, 0],
            ['borrowed', 'Imran Sheikh', 3000, 3000],
            ['borrowed', 'Vinod Menon', 1500, 500],
        ];

        foreach ($rows as $i => [$type, $party, $total, $paid]) {
            $balance = $total - $paid;

            LedgerEntry::create([
                'type' => $type,
                'entry_date' => $this->workingDay($i * 6)->toDateString(),
                'party_name' => $party,
                'total_amount' => $total,
                'paid_amount' => $paid,
                'balance_amount' => $balance,
                'status' => $balance <= 0 ? 'returned' : ($paid > 0 ? 'partial' : 'pending'),
                'return_date' => $balance <= 0 ? $this->workingDay($i * 6 - 3)->toDateString() : null,
                'created_by' => $user->id,
            ]);
        }
    }

    private function cashCounts(User $user): void
    {
        foreach ([0, 14, 30] as $offset) {
            $lines = ['500' => 4, '200' => 6, '100' => 12, '50' => 8, '20' => 10, '10' => 15];
            $total = 0;
            foreach ($lines as $denomination => $count) {
                $total += ((int) $denomination) * $count;
            }

            CashCount::create([
                'count_date' => $this->workingDay($offset)->toDateString(),
                'lines' => $lines,
                'total_aed' => $total,
                'expected_aed' => $total,
                'remarks' => 'End of day count',
                'created_by' => $user->id,
            ]);
        }
    }

    /**
     * Spreads records back from today across the demo window, skipping Friday
     * and Saturday so the books read like a UAE working week.
     */
    private function workingDay(int $index): Carbon
    {
        $step = max(1, (int) floor(self::SPAN_DAYS / self::TRANSACTION_COUNT));
        $date = Carbon::today()->subDays(min($index * $step, self::SPAN_DAYS));

        while (in_array($date->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true)) {
            $date->subDay();
        }

        return $date;
    }
}
