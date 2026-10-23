<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word().' Plan',
            'code' => strtoupper($this->faker->unique()->bothify('PLAN-###')),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 100, 5000),
            'currency' => 'NGN',
            'data_allowance_bytes' => 1024 * 1024 * 1024,
            'is_unlimited' => false,
            'validity_seconds' => 86400 * 30,
            'is_active' => true,
        ];
    }
}
