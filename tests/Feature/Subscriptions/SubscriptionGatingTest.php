<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_admin_without_subscription_is_redirected(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('subscriptions.index'));
    }

    public function test_network_admin_with_expired_subscription_is_redirected(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        Subscription::factory()->expired()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('subscriptions.index'));
    }

    public function test_network_admin_with_active_subscription_can_access_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $this->grantSubscription($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_network_admin_can_always_reach_subscription_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');

        $this->actingAs($user)
            ->get('/subscription')
            ->assertOk();
    }

    public function test_super_admin_is_not_subscription_gated(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_support_role_is_not_subscription_gated(): void
    {
        $user = User::factory()->create();
        $user->assignRole('support');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_network_admin_can_request_renewal(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');

        $this->actingAs($user);
        \Livewire\Livewire::test('pages::subscriptions.index')
            ->call('request')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    }
}
