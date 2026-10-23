<?php

namespace Database\Factories;

use App\Enums\RouterStatus;
use App\Models\Router;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Router>
 */
class RouterFactory extends Factory
{
    protected $model = Router::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $third = fake()->unique()->numberBetween(10, 250);
        $fourth = fake()->numberBetween(2, 250);

        return [
            'name' => 'LAB-'.fake()->unique()->numerify('###'),
            'location' => fake()->city(),
            'site' => 'Test Site',
            'host' => "10.20.{$third}.{$fourth}",
            'rest_port' => 443,
            'api_username' => 'mmutech-api',
            'api_password' => 'test-router-password-'.fake()->password(12, 24),
            'tls_verify' => true,
            'status' => RouterStatus::Pending,
            'monitoring_enabled' => true,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RouterStatus::Online,
            'identity' => 'MMUTech Lab Router',
            'ros_version' => '7.16.2',
            'last_checked_at' => now(),
            'last_seen_at' => now(),
            'latency_ms' => 24,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RouterStatus::Offline,
            'last_checked_at' => now(),
            'consecutive_failures' => 3,
            'last_error_category' => 'timeout',
            'last_error_message' => 'Connection timed out',
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RouterStatus::Disabled,
            'monitoring_enabled' => false,
        ]);
    }
}
