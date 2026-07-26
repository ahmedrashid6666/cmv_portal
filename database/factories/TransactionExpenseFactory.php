<?php
namespace Database\Factories;
use App\Models\Transaction;
use App\Models\TransactionExpense;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<TransactionExpense> */
class TransactionExpenseFactory extends Factory
{
    protected $model = TransactionExpense::class;
    public function definition(): array
    {
        return ['transaction_id' => Transaction::factory(), 'description' => fake()->word(), 'amount' => 10];
    }
}
