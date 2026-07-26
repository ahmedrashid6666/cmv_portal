<?php
namespace Database\Factories;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\CreditPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<CreditPayment> */
class CreditPaymentFactory extends Factory
{
    protected $model = CreditPayment::class;
    public function definition(): array
    {
        return ['transaction_id' => Transaction::factory(), 'payment_date' => now()->toDateString(), 'amount' => 50, 'payment_method_id' => PaymentMethod::factory()];
    }
}
