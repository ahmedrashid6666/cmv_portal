<?php
namespace Database\Factories;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;
    public function definition(): array
    {
        return ['number' => strtoupper(fake()->bothify('#####??'))];
    }
}
