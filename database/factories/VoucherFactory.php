<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Router;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Voucher>
 */
class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    public function definition(): array
    {
        $router = Router::factory()->create();
        $plan = Plan::factory()->create();
        return [
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'username' => strtoupper(Str::random(8)),
            'password' => Str::random(8),
            'data_allowance_bytes' => $plan->data_allowance_bytes,
            'validity_seconds' => $plan->validity_seconds,
            'lifecycle_status' => 'pending',
            'provisioning_status' => 'pending',
        ];
    }
}
