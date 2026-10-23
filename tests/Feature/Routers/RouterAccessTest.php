<?php

namespace Tests\Feature\Routers;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_router_access_cannot_view_router(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $this->grantSubscription($user);
        $router = Router::factory()->create();

        $this->actingAs($user);
        $this->get('/routers/'.$router->public_id)->assertForbidden();
    }

    public function test_user_with_router_access_can_view_router(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $this->grantSubscription($user);
        $router = Router::factory()->create();
        $router->users()->attach($user->id);

        $this->actingAs($user);
        $this->get('/routers/'.$router->public_id)->assertOk();
    }

    public function test_router_index_shows_only_accessible_routers(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $this->grantSubscription($user);
        $allowed = Router::factory()->create(['name' => 'ALLOWED-ROUTER']);
        $denied = Router::factory()->create(['name' => 'DENIED-ROUTER']);
        $allowed->users()->attach($user->id);

        $this->actingAs($user);
        $response = $this->get('/routers');
        $response->assertOk();
        $response->assertSee('ALLOWED-ROUTER');
        $response->assertDontSee('DENIED-ROUTER');
    }

    public function test_creator_gets_explicit_access_on_create(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $this->grantSubscription($user);
        $router = Router::factory()->create(['created_by' => $user->id]);
        $router->users()->syncWithoutDetaching([$user->id => ['granted_by' => $user->id]]);

        $this->assertTrue($router->users()->whereKey($user->id)->exists());
    }
}
