<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Router;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Voucher>
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
            'hotspot_profile' => 'default',
            'username' => strtoupper(Str::random(8)),
            'password' => Str::random(8),
            'data_allowance_bytes' => $plan->data_allowance_bytes,
            'time_allowance_seconds' => $plan->time_allowance_seconds,
            'upload_speed_kbps' => $plan->upload_speed_kbps,
            'download_speed_kbps' => $plan->download_speed_kbps,
            'validity_seconds' => $plan->validity_seconds,
            'lifecycle_status' => 'pending',
            'provisioning_status' => 'pending',
        ];
    }
}
