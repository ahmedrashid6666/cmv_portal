<?php
namespace Database\Factories;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<TransactionCommission> */
class TransactionCommissionFactory extends Factory
{
    protected $model = TransactionCommission::class;
    public function definition(): array
    {
        return ['transaction_id' => Transaction::factory(), 'label' => 'Com-1', 'amount' => 25, 'type' => 'charged_to_customer'];
    }
}
