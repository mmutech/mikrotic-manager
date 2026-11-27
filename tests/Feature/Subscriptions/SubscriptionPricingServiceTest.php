<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionType;
use App\Models\User;
use App\Services\SubscriptionPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'mmutech.subscription.currency' => 'NGN',
            'mmutech.subscription.prices.monthly' => 1000,
            'mmutech.subscription.prices.yearly' => 10000,
        ]);
    }

    public function test_it_returns_configured_prices_for_each_type(): void
    {
        $pricing = app(SubscriptionPricingService::class);

        $this->assertSame(1000.0, $pricing->priceFor(SubscriptionType::Monthly));
        $this->assertSame(10000.0, $pricing->priceFor(SubscriptionType::Yearly));
        $this->assertSame('NGN', $pricing->currency());
        $this->assertSame([
            [
                'value' => 'monthly',
                'label' => 'Monthly',
                'days' => 30,
                'price' => 1000.0,
                'currency' => 'NGN',
            ],
            [
                'value' => 'yearly',
                'label' => 'Yearly',
                'days' => 365,
                'price' => 10000.0,
                'currency' => 'NGN',
            ],
        ], $pricing->options());
    }

    public function test_admin_updated_prices_override_config_defaults(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        $pricing = app(SubscriptionPricingService::class);
        $pricing->updatePrices([
            'monthly' => 1500,
            'yearly' => 15000,
        ], $actor);

        $this->assertSame(1500.0, $pricing->priceFor(SubscriptionType::Monthly));
        $this->assertSame(15000.0, $pricing->priceFor(SubscriptionType::Yearly));
        $this->assertSame(1500.0, $pricing->options()[0]['price']);
        $this->assertSame(15000.0, $pricing->options()[1]['price']);
    }
}
