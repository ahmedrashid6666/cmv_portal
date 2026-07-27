<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DefaultDataSeeder extends Seeder
{
    public function run(): void
    {
        // Payment methods (type buckets balances: cash / bank / credit / other)
        $methods = [
            ['name' => 'Cash', 'type' => 'cash'],
            ['name' => 'Bank', 'type' => 'bank'],
            ['name' => 'Credit', 'type' => 'credit'],
            ['name' => 'Card', 'type' => 'bank'],
            ['name' => 'Cheque', 'type' => 'bank'],
            ['name' => 'Online Transfer', 'type' => 'bank'],
        ];
        foreach ($methods as $m) {
            PaymentMethod::updateOrCreate(['name' => $m['name']], ['type' => $m['type']]);
        }

        // Expense categories (from the workbook's common expenses)
        $categories = [
            'ZAJEL Payment', 'Courier Charges', 'Typing Charges', 'Office Expenses',
            'Fuel', 'Salik', 'Parking', 'Printing', 'Miscellaneous',
        ];
        foreach ($categories as $name) {
            ExpenseCategory::updateOrCreate(['name' => $name]);
        }

        // System settings
        Setting::put('company_name', 'CMV Shipping');
        Setting::put('currency', 'AED');
        if (Setting::get('vat_rate') === null) {
            Setting::put('vat_rate', 0);
        }

        // Company / invoice details (from cmvshipping.com — Dubai HQ)
        foreach ([
            'company_address' => 'Suhail Bin Ghedayer Building, Lehbab Second, Shop No 17, Dubai, UAE',
            'company_phone' => '+971 58 94 34 366',
            'company_email' => 'info@cmvshipping.com',
            'company_trn' => '',
            'invoice_footer' => 'Thank you for your business.',
        ] as $key => $value) {
            if (Setting::get($key) === null) {
                Setting::put($key, $value);
            }
        }

        // Super admin (idempotent). Password only set on first creation.
        // Pass the PLAIN password — the User model's `hashed` cast hashes it once.
        // (Do not pre-hash here, or it risks a double-hash.)
        User::firstOrCreate(
            ['email' => 'admin@cmvshipping.com'],
            [
                'name' => 'CMV Admin',
                'password' => env('SEED_ADMIN_PASSWORD', 'cmv12345'),
                'role' => Role::SUPER_ADMIN->value,
                'email_verified_at' => now(),
            ],
        );
    }
}
