<?php

namespace Tests\Feature\Plans;

use App\Models\Plan;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlanRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_code_is_generated_on_create(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $router = Router::factory()->create();

        $this->actingAs($admin);

        Livewire::test('pages::plans.create')
            ->set('name', 'Daily 1GB')
            ->set('router_id', (string) $router->id)
            ->set('price', '500')
            ->set('currency', 'NGN')
            ->set('data_allowance_mb', '1024')
            ->set('validity_days', '1')
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::firstOrFail();
        $this->assertMatchesRegularExpression('/^PLN-[A-Z0-9]{8}$/', $plan->code);
        $this->assertSame($router->id, $plan->router_id);
    }

    public function test_generated_plan_codes_are_unique(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $router = Router::factory()->create();

        $this->actingAs($admin);

        foreach ([1, 2, 3] as $i) {
            Livewire::test('pages::plans.create')
                ->set('name', 'Plan '.$i)
                ->set('router_id', (string) $router->id)
                ->set('price', '100')
                ->set('currency', 'NGN')
                ->set('data_allowance_mb', '512')
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(3, Plan::query()->distinct()->count('code'));
    }

    public function test_network_admin_cannot_view_plan_for_unassigned_router(): void
    {
        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $assigned = Router::factory()->create();
        $other = Router::factory()->create();
        $assigned->users()->attach($user->id);

        $ownPlan = Plan::factory()->create(['router_id' => $assigned->id]);
        $otherPlan = Plan::factory()->create(['router_id' => $other->id]);

        $this->assertTrue($user->can('view', $ownPlan));
        $this->assertFalse($user->can('view', $otherPlan));
    }
}
