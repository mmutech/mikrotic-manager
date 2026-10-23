<?php

namespace Tests\Feature\Plans;

use App\Models\Plan;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_cannot_manage_plans(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permissions::PLANS_VIEW);
        $plan = Plan::factory()->create();
        $this->assertFalse($user->can('update', $plan));
    }

    public function test_network_admin_with_plans_manage_can_update(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permissions::PLANS_VIEW, Permissions::PLANS_MANAGE]);
        $plan = Plan::factory()->create();
        $this->assertTrue($user->can('update', $plan));
    }
}
