<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $customer = Customer::factory()->create();
        $plan = Plan::factory()->create();
        $router = Router::factory()->create();
        return [
            'reference' => 'TXN-'.strtoupper(Str::random(16)),
            'provider' => 'paystack',
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'amount' => $plan->price,
            'currency' => 'NGN',
            'status' => 'pending',
            'channel' => 'card',
        ];
    }
}
