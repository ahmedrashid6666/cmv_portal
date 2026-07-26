<?php

use App\Enums\Role;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
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
        ->and(Setting::get('company_name'))->toBe('CMV Shipping');
});

it('seeds a super admin user', function () {
    $admin = User::where('email', 'admin@cmvshipping.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->role)->toBe(Role::SUPER_ADMIN);
});

it('is idempotent', function () {
    $this->seed(DefaultDataSeeder::class);

    expect(PaymentMethod::where('name', 'Cash')->count())->toBe(1)
        ->and(User::where('email', 'admin@cmvshipping.com')->count())->toBe(1);
});
