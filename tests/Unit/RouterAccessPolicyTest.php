<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\User;
use App\Policies\RouterPolicy;
use PHPUnit\Framework\TestCase;

class RouterAccessPolicyTest extends TestCase
{
    public function test_admin_can_view_a_router_that_is_not_explicitly_assigned(): void
    {
        $router = new Router(['id' => 1]);

        $admin = $this->createMock(User::class);
        $admin->method('isSuperAdmin')->willReturn(true);
        $admin->method('can')->with('routers.view')->willReturn(true);
        $admin->expects($this->never())->method('canAccessRouter');

        $this->assertTrue((new RouterPolicy)->view($admin, $router));
    }

    public function test_non_admin_can_only_view_assigned_routers(): void
    {
        $router = new Router(['id' => 1]);
        $policy = new RouterPolicy;

        $assignedUser = $this->createMock(User::class);
        $assignedUser->method('isSuperAdmin')->willReturn(false);
        $assignedUser->method('can')->with('routers.view')->willReturn(true);
        $assignedUser->method('canAccessRouter')->with($router)->willReturn(true);

        $unassignedUser = $this->createMock(User::class);
        $unassignedUser->method('isSuperAdmin')->willReturn(false);
        $unassignedUser->method('can')->with('routers.view')->willReturn(true);
        $unassignedUser->method('canAccessRouter')->with($router)->willReturn(false);

        $this->assertTrue($policy->view($assignedUser, $router));
        $this->assertFalse($policy->view($unassignedUser, $router));
    }
}
