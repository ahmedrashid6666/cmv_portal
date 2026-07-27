<?php
namespace Database\Factories;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<LedgerEntry> */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;
    public function definition(): array
    {
        return [
            'type' => LedgerEntry::TYPE_CREDIT,
            'entry_date' => now()->toDateString(),
            'party_name' => fake()->name(),
            'reference' => fake()->optional()->firstName(),
            'vehicle_number' => strtoupper(fake()->bothify('#####??')),
            'total_amount' => 10000,
            'paid_amount' => 0,
        ];
    }
    public function borrowed(): static { return $this->state(['type' => LedgerEntry::TYPE_BORROWED]); }
}
