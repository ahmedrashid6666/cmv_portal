<?php
namespace Database\Factories;
use App\Models\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Reference> */
class ReferenceFactory extends Factory
{
    protected $model = Reference::class;
    public function definition(): array
    {
        return ['name' => fake()->firstName(), 'contact' => fake()->phoneNumber()];
    }
}
