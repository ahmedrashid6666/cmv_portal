<?php
namespace Database\Factories;
use App\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Bank> */
class BankFactory extends Factory
{
    protected $model = Bank::class;
    public function definition(): array
    {
        return ['name' => fake()->company().' Bank', 'account_no' => fake()->bankAccountNumber(), 'opening_balance' => 0, 'is_customs' => false];
    }

    public function customs(): static
    {
        return $this->state(['is_customs' => true]);
    }
}
