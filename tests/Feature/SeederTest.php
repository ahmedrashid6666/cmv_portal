<?php

use App\Enums\Role;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Database\Seeders\DefaultDataSeeder;

beforeEach(fn () => $this->seed(DefaultDataSeeder::class));

it('seeds the six payment methods with correct buckets', function () {
    expect(PaymentMethod::pluck('name')->all())
        ->toContain('Cash', 'Bank', 'Credit', 'Card', 'Cheque', 'Online Transfer')
        ->and(PaymentMethod::where('name', 'Cash')->value('type'))->toBe('cash')
        ->and(PaymentMethod::where('name', 'Credit')->value('type'))->toBe('credit');
});

it('seeds the default expense categories', function () {
    expect(ExpenseCategory::pluck('name')->all())
        ->toContain('ZAJEL Payment', 'Courier Charges', 'Typing Charges', 'Fuel', 'Salik', 'Parking', 'Printing', 'Miscellaneous');
});

it('seeds default settings with vat_rate 0', function () {
    expect(Setting::get('vat_rate'))->toBe('0')
        ->and(Setting::get('currency'))->toBe('AED')
        ->and(Setting::get('company_name'))->toBe(Branding::DEFAULT_NAME);
});

it('seeds a super admin user whose password actually works (no double-hash)', function () {
    $admin = User::where('email', 'admin@harkcreation.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->role)->toBe(Role::SUPER_ADMIN)
        // password must verify against the plain default — guards the double-hash bug
        ->and(\Illuminate\Support\Facades\Hash::check('admin12345', $admin->password))->toBeTrue();
});

it('is idempotent', function () {
    $this->seed(DefaultDataSeeder::class);

    expect(PaymentMethod::where('name', 'Cash')->count())->toBe(1)
        ->and(User::where('role', Role::SUPER_ADMIN->value)->count())->toBe(1);
});

it('never overwrites settings an operator has edited', function () {
    Setting::put('company_name', 'Gulf Freight LLC');
    Setting::put('currency', 'USD');
    Setting::put('invoice_footer', 'Pay within 30 days.');

    $this->seed(DefaultDataSeeder::class);

    expect(Setting::get('company_name'))->toBe('Gulf Freight LLC')
        ->and(Setting::get('currency'))->toBe('USD')
        ->and(Setting::get('invoice_footer'))->toBe('Pay within 30 days.');
});

it('does not mint a second super admin when the seeded email changes', function () {
    putenv('SEED_ADMIN_EMAIL=someone-else@example.com');

    $this->seed(DefaultDataSeeder::class);

    putenv('SEED_ADMIN_EMAIL');

    expect(User::where('role', Role::SUPER_ADMIN->value)->count())->toBe(1)
        ->and(User::where('email', 'someone-else@example.com')->exists())->toBeFalse();
});
