<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Database\Seeder;

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

        // System settings and branding.
        //
        // Every value here is seed-if-absent: this seeder is re-run on each
        // deploy, and an operator's edits in the Settings page must survive
        // that. (company_name and currency used to be written unconditionally,
        // which silently reverted them on every deploy.)
        foreach ([
            'company_name' => Branding::DEFAULT_NAME,
            'company_logo' => Branding::DEFAULT_LOGO,
            'company_og_image' => Branding::DEFAULT_OG_IMAGE,
            'currency' => 'AED',
            'vat_rate' => 0,
            'company_address' => '',
            'company_phone' => '+971 56 689 0484',
            'company_email' => 'info@harkcreation.com',
            'company_trn' => '',
            'invoice_footer' => 'Thank you for your business.',
        ] as $key => $value) {
            if (Setting::get($key) === null) {
                Setting::put($key, $value);
            }
        }

        $this->seedSuperAdmin();
    }

    /**
     * Creates the first super admin, and only the first.
     *
     * Keyed on "does any super admin exist" rather than on a fixed email so
     * that changing the seeded address can never mint a second privileged
     * account (with a default password) on an install that already has one.
     */
    private function seedSuperAdmin(): void
    {
        if (User::query()->where('role', Role::SUPER_ADMIN->value)->exists()) {
            return;
        }

        // Pass the PLAIN password — the User model's `hashed` cast hashes it once.
        // (Do not pre-hash here, or it risks a double-hash.)
        User::create([
            'email' => env('SEED_ADMIN_EMAIL', 'admin@harkcreation.com'),
            'name' => env('SEED_ADMIN_NAME', 'Administrator'),
            'password' => env('SEED_ADMIN_PASSWORD', 'admin12345'),
            'role' => Role::SUPER_ADMIN->value,
            'email_verified_at' => now(),
        ]);
    }
}
