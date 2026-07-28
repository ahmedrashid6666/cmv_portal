<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class OfficeExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'expense_date' => now()->toDateString(),
            'expense_category_id' => null,
            'description' => fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 10, 2000),
            'currency' => 'AED',
            'payment_method_id' => PaymentMethod::factory()->create(['type' => 'cash'])->id,
            'remarks' => null,
        ];
    }
}
