<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Router;
use App\Policies\RouterPolicy;
use App\Support\Redactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RouterPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_bypasses_most_abilities_but_not_view_credentials(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();

        $this->actingAs($user);

        $this->assertTrue(Gate::allows('viewAny', Router::class));
        $this->assertTrue(Gate::allows('view', $router));
        $this->assertTrue(Gate::allows('create', Router::class));
        $this->assertTrue(Gate::allows('update', $router));
        $this->assertTrue(Gate::allows('test', $router));
        $this->assertTrue(Gate::allows('manageMonitoring', $router));
        $this->assertTrue(Gate::allows('grantAccess', $router));
        $this->assertTrue(Gate::allows('revokeAccess', $router));
        $this->assertTrue(Gate::allows('delete', $router));
        $this->assertFalse(Gate::allows('viewCredentials', $router));
    }

    public function test_network_admin_needs_explicit_router_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $router = Router::factory()->create();

        $this->actingAs($user);

        $this->assertTrue(Gate::allows('viewAny', Router::class));
        $this->assertFalse(Gate::allows('view', $router));
        $this->assertFalse(Gate::allows('update', $router));
        $this->assertFalse(Gate::allows('test', $router));

        $router->users()->attach($user->id);

        $this->assertTrue(Gate::allows('view', $router));
        $this->assertTrue(Gate::allows('update', $router));
        $this->assertTrue(Gate::allows('test', $router));
    }

    public function test_only_super_admin_can_grant_or_revoke_access(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $network = User::factory()->create();
        $network->assignRole('network-admin');
        $router = Router::factory()->create();
        $router->users()->attach($admin->id);
        $router->users()->attach($network->id);

        $this->actingAs($network);
        $this->assertFalse(Gate::allows('grantAccess', $router));
        $this->assertFalse(Gate::allows('revokeAccess', $router));

        $this->actingAs($admin);
        $this->assertTrue(Gate::allows('grantAccess', $router));
        $this->assertTrue(Gate::allows('revokeAccess', $router));
    }
}
