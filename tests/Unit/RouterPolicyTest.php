<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\User;
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

    public function test_super_admin_sees_all_routers(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $first = Router::factory()->create();
        $second = Router::factory()->create();

        $visible = Router::query()->visibleTo($admin)->pluck('routers.id')->all();

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $visible);
    }

    public function test_non_admin_sees_only_assigned_routers(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $assigned = Router::factory()->create();
        $unassigned = Router::factory()->create();
        $assigned->users()->attach($user->id);

        $this->actingAs($user);

        $visible = Router::query()->visibleTo($user)->pluck('routers.id')->all();

        $this->assertSame([$assigned->id], $visible);
        $this->assertTrue(Gate::allows('view', $assigned));
        $this->assertFalse(Gate::allows('view', $unassigned));
    }
}
