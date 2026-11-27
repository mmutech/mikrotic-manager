<?php

namespace Tests\Feature\Seeders;

use App\Enums\SubscriptionType;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\SubscriberUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SubscriberUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'mmutech.seed.network_admin_name' => 'Seeded Network Admin',
            'mmutech.seed.network_admin_email' => 'network-seed@example.test',
            'mmutech.seed.network_admin_password' => 'network-password',
            'mmutech.seed.sales_agent_name' => 'Seeded Sales Agent',
            'mmutech.seed.sales_agent_email' => 'sales-seed@example.test',
            'mmutech.seed.sales_agent_password' => 'sales-password',
            'mmutech.seed.subscriber_subscription_type' => SubscriptionType::Yearly->value,
            'mmutech.subscription.currency' => 'NGN',
            'mmutech.subscription.prices.yearly' => 12000,
        ]);
    }

    public function test_it_seeds_both_subscriber_roles_with_active_subscriptions(): void
    {
        $this->seed(SubscriberUsersSeeder::class);

        $networkAdmin = User::whereEmail('network-seed@example.test')->firstOrFail();
        $salesAgent = User::whereEmail('sales-seed@example.test')->firstOrFail();

        $this->assertTrue($networkAdmin->hasRole(Roles::NETWORK_ADMIN));
        $this->assertTrue($networkAdmin->isActive());
        $this->assertTrue(Hash::check('network-password', $networkAdmin->password));
        $this->assertTrue($networkAdmin->hasActiveSubscription());
        $this->assertSame(SubscriptionType::Yearly, $networkAdmin->activeSubscription()->type);
        $this->assertSame('12000.00', (string) $networkAdmin->activeSubscription()->amount);
        $this->assertSame('NGN', $networkAdmin->activeSubscription()->currency);
        $this->assertSame(
            now()->addDays(365)->toDateString(),
            $networkAdmin->activeSubscription()->expires_at->toDateString(),
        );

        $this->assertTrue($salesAgent->hasRole(Roles::SALES_AGENT));
        $this->assertTrue($salesAgent->isActive());
        $this->assertTrue(Hash::check('sales-password', $salesAgent->password));
        $this->assertTrue($salesAgent->hasActiveSubscription());
        $this->assertSame(SubscriptionType::Yearly, $salesAgent->activeSubscription()->type);
        $this->assertSame('12000.00', (string) $salesAgent->activeSubscription()->amount);
        $this->assertSame('NGN', $salesAgent->activeSubscription()->currency);
        $this->assertSame(
            now()->addDays(365)->toDateString(),
            $salesAgent->activeSubscription()->expires_at->toDateString(),
        );
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(SubscriberUsersSeeder::class);
        $this->seed(SubscriberUsersSeeder::class);

        $networkAdmin = User::whereEmail('network-seed@example.test')->firstOrFail();
        $salesAgent = User::whereEmail('sales-seed@example.test')->firstOrFail();

        $this->assertSame(1, User::whereEmail('network-seed@example.test')->count());
        $this->assertSame(1, User::whereEmail('sales-seed@example.test')->count());
        $this->assertSame(1, $networkAdmin->subscriptions()->count());
        $this->assertSame(1, $salesAgent->subscriptions()->count());
        $this->assertSame([Roles::NETWORK_ADMIN], $networkAdmin->roles->pluck('name')->all());
        $this->assertSame([Roles::SALES_AGENT], $salesAgent->roles->pluck('name')->all());
    }
}
