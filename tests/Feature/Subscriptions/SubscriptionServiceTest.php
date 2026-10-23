<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_activate(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('support');
        $target = User::factory()->create();
        $target->assignRole('network-admin');

        $this->actingAs($actor);

        $this->expectException(RuntimeException::class);
        app(SubscriptionService::class)->activate($target);
    }

    public function test_only_super_admin_can_deactivate(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('support');
        $target = User::factory()->create();
        $target->assignRole('network-admin');

        $this->actingAs($actor);

        $this->expectException(RuntimeException::class);
        app(SubscriptionService::class)->deactivate($target);
    }

    public function test_super_admin_can_activate_subscription(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');
        $target = User::factory()->create();
        $target->assignRole('network-admin');

        $this->actingAs($actor);

        $subscription = app(SubscriptionService::class)->activate($target, 45, 'premium', 15000);

        $this->assertSame('active', $subscription->status);
        $this->assertSame('premium', $subscription->plan);
        $this->assertTrue($subscription->isActive());
        $this->assertSame(now()->addDays(45)->toDateString(), $subscription->expires_at->toDateString());
        $this->assertTrue($target->fresh()->hasActiveSubscription());
    }

    public function test_activation_extends_from_existing_expiry(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');
        $target = User::factory()->create();
        $target->assignRole('network-admin');
        $current = Subscription::factory()->create([
            'user_id' => $target->id,
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($actor);
        $new = app(SubscriptionService::class)->activate($target, 30);

        $this->assertSame(
            $current->expires_at->copy()->addDays(30)->toDateString(),
            $new->expires_at->toDateString(),
        );
        $this->assertSame(1, $target->subscriptions()->where('status', 'active')->count());
        $this->assertSame('expired', $current->fresh()->status);
    }

    public function test_activation_approves_pending_requests(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');
        $target = User::factory()->create();
        $target->assignRole('network-admin');
        $pending = Subscription::factory()->pending()->create(['user_id' => $target->id]);

        $this->actingAs($actor);
        app(SubscriptionService::class)->activate($target);

        $this->assertSame('approved', $pending->fresh()->status);
    }

    public function test_super_admin_can_deactivate(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');
        $target = User::factory()->create();
        $target->assignRole('network-admin');
        $subscription = Subscription::factory()->create(['user_id' => $target->id]);

        $this->actingAs($actor);
        $count = app(SubscriptionService::class)->deactivate($target);

        $this->assertSame(1, $count);
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertFalse($target->fresh()->hasActiveSubscription());
    }
}
