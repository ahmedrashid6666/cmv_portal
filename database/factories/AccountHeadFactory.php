<?php
namespace Database\Factories;
use App\Models\AccountHead;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<AccountHead> */
class AccountHeadFactory extends Factory
{
    protected $model = AccountHead::class;
    public function definition(): array
    {
        return ['name' => fake()->word(), 'type' => 'income'];
    }
}
