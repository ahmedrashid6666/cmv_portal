<?php

namespace App\Support;

use App\Models\AccountHead;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Vehicle;

/**
 * Declarative definition of every master-data type. One place drives the
 * routes, controller, and React table/form so masters stay DRY.
 */
class MasterRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'customers' => [
                'model' => Customer::class,
                'label' => 'Customers',
                'singular' => 'Customer',
                'columns' => ['name' => 'Name', 'contact' => 'Contact', 'opening_balance' => 'Opening Balance'],
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['name' => 'contact', 'label' => 'Contact', 'type' => 'text'],
                    ['name' => 'opening_balance', 'label' => 'Opening Balance', 'type' => 'number', 'default' => 0],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ],
            ],
            'references' => [
                'model' => Reference::class,
                'label' => 'References',
                'singular' => 'Reference',
                'columns' => ['name' => 'Name', 'company' => 'Company', 'contact' => 'Contact'],
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['name' => 'company', 'label' => 'Company', 'type' => 'text'],
                    ['name' => 'contact', 'label' => 'Contact', 'type' => 'text'],
                ],
            ],
            'vehicles' => [
                'model' => Vehicle::class,
                'label' => 'Vehicles',
                'singular' => 'Vehicle',
                'columns' => ['number' => 'Vehicle Number', 'notes' => 'Notes'],
                'fields' => [
                    ['name' => 'number', 'label' => 'Vehicle Number', 'type' => 'text', 'required' => true],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'text'],
                ],
            ],
            'expense-categories' => [
                'model' => ExpenseCategory::class,
                'label' => 'Expense Categories',
                'singular' => 'Expense Category',
                'columns' => ['name' => 'Name'],
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ],
            ],
            'payment-methods' => [
                'model' => PaymentMethod::class,
                'label' => 'Payment Methods',
                'singular' => 'Payment Method',
                'columns' => ['name' => 'Name', 'type' => 'Balance Bucket'],
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['name' => 'type', 'label' => 'Balance Bucket', 'type' => 'select', 'options' => ['cash', 'bank', 'credit', 'other'], 'required' => true],
                ],
            ],
            'banks' => [
                'model' => Bank::class,
                'label' => 'Banks',
                'singular' => 'Bank',
                'columns' => ['name' => 'Name', 'account_no' => 'Account No', 'opening_balance' => 'Opening Balance'],
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['name' => 'account_no', 'label' => 'Account No', 'type' => 'text'],
                    ['name' => 'opening_balance', 'label' => 'Opening Balance', 'type' => 'number', 'default' => 0],
                ],
            ],
            'account-heads' => [
                'model' => AccountHead::class,
                'label' => 'Account Heads',
                'singular' => 'Account Head',
                'columns' => ['name' => 'Name', 'type' => 'Type'],
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['asset', 'liability', 'income', 'expense', 'equity'], 'required' => true],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        $all = self::all();
        abort_unless(isset($all[$key]), 404);

        return $all[$key] + ['key' => $key];
    }

    /**
     * Validation rules for a master type's fields.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $key): array
    {
        $config = self::get($key);
        $rules = [];
        foreach ($config['fields'] as $field) {
            $r = [];
            $r[] = ($field['required'] ?? false) ? 'required' : 'nullable';
            $r[] = match ($field['type']) {
                'number' => 'numeric',
                'select' => 'in:'.implode(',', $field['options']),
                default => 'string',
            };
            $rules[$field['name']] = $r;
        }

        return $rules;
    }
}
