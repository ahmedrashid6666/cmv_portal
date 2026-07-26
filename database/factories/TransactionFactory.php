<?php
namespace Database\Factories;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;
    public function definition(): array
    {
        return [
            'transaction_date' => now()->toDateString(),
            'invoice_no' => (string) fake()->unique()->numberBetween(50000, 99999),
            'boe_no' => (string) fake()->numberBetween(1000000000, 9999999999),
            'customer_id' => Customer::factory(),
            'reference_id' => null,
            'vehicle_id' => null,
            'customs_fees' => 75, 'gov_fees' => 0, 'profit' => 25,
            'vat_rate' => 0, 'vat_amount' => 0, 'total_amount' => 100,
            'payment_method_id' => PaymentMethod::factory(),
            'credit_amount' => 0,
            'grand_total' => 100, 'net_profit' => 25,
        ];
    }
}
