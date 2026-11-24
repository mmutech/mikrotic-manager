<?php

namespace Tests\Feature\Plans;

use App\Contracts\RouterGateway;
use App\Enums\ProvisioningStatus;
use App\Models\Plan;
use App\Models\Router;
use App\Models\RouterPlanProfile;
use App\Models\User;
use App\Models\Voucher;
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
        $this->mockRouterProfiles(['daily-1gb'], '512k/2M');

        $this->actingAs($admin);

        Livewire::test('pages::plans.create')
            ->set('name', 'Daily 1GB')
            ->set('router_id', (string) $router->id)
            ->call('loadHotspotProfiles')
            ->set('hotspot_profile', 'daily-1gb')
            ->set('price', '500')
            ->set('currency', 'NGN')
            ->set('data_allowance_mb', '1024')
            ->set('validity_days', '1')
            ->set('time_allowance_seconds', '3600')
            ->call('syncHotspotProfileSpeeds')
            ->assertSet('upload_speed_kbps', '512')
            ->assertSet('download_speed_kbps', '2000')
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::firstOrFail();
        $this->assertMatchesRegularExpression('/^PLN-[A-Z0-9]{8}$/', $plan->code);
        $this->assertSame($router->id, $plan->router_id);
        $this->assertSame(3600, $plan->time_allowance_seconds);
        $this->assertSame(512, $plan->upload_speed_kbps);
        $this->assertSame(2000, $plan->download_speed_kbps);
        $this->assertDatabaseHas('router_plan_profiles', [
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'hotspot_profile' => 'daily-1gb',
        ]);
    }

    public function test_generated_plan_codes_are_unique(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $router = Router::factory()->create();
        $this->mockRouterProfiles(['plan-1', 'plan-2', 'plan-3']);

        $this->actingAs($admin);

        foreach ([1, 2, 3] as $i) {
            Livewire::test('pages::plans.create')
                ->set('name', 'Plan '.$i)
                ->set('router_id', (string) $router->id)
                ->call('loadHotspotProfiles')
                ->set('hotspot_profile', 'plan-'.$i)
                ->set('price', '100')
                ->set('currency', 'NGN')
                ->set('data_allowance_mb', '512')
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(3, Plan::query()->distinct()->count('code'));
    }

    public function test_multiple_plans_can_share_the_same_router_hotspot_profile(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $router = Router::factory()->create();
        $this->mockRouterProfiles(['shared-access'], '512k/2M');

        $this->actingAs($admin);

        foreach ([
            ['Daily access', '100', '500'],
            ['Weekly access', '500', '2048'],
        ] as [$name, $price, $dataAllowanceMb]) {
            Livewire::test('pages::plans.create')
                ->set('name', $name)
                ->set('router_id', (string) $router->id)
                ->call('loadHotspotProfiles')
                ->set('hotspot_profile', 'shared-access')
                ->set('price', $price)
                ->set('currency', 'NGN')
                ->set('data_allowance_mb', $dataAllowanceMb)
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertDatabaseCount('plans', 2);
        $this->assertDatabaseCount('router_plan_profiles', 2);
        $this->assertSame(
            2,
            RouterPlanProfile::query()
                ->where('router_id', $router->id)
                ->where('hotspot_profile', 'shared-access')
                ->count(),
        );
    }

    public function test_selecting_a_router_loads_only_its_hotspot_user_profiles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $firstRouter = Router::factory()->create();
        $secondRouter = Router::factory()->create();
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->exactly(2))
            ->method('hotspotUserProfiles')
            ->willReturnCallback(function (Router $router) use ($firstRouter): array {
                $profileName = $router->is($firstRouter) ? 'first-router-profile' : 'second-router-profile';

                return [[
                    'name' => $profileName,
                    'rate_limit' => null,
                    'shared_users' => '1',
                ]];
            });
        $this->app->instance(RouterGateway::class, $gateway);

        $this->actingAs($admin);

        Livewire::test('pages::plans.create')
            ->set('router_id', (string) $firstRouter->id)
            ->call('loadHotspotProfiles')
            ->assertSee('first-router-profile')
            ->set('hotspot_profile', 'first-router-profile')
            ->set('router_id', (string) $secondRouter->id)
            ->assertSet('hotspot_profile', '')
            ->assertSet('hotspotProfiles', [])
            ->call('loadHotspotProfiles')
            ->assertSee('second-router-profile')
            ->assertDontSee('first-router-profile')
            ->assertSet('hotspotProfilesLoaded', true);
    }

    public function test_finite_plan_data_allowance_must_be_positive(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $router = Router::factory()->create();
        $this->mockRouterProfiles(['default']);

        $this->actingAs($admin);

        Livewire::test('pages::plans.create')
            ->set('name', 'Zero data')
            ->set('router_id', (string) $router->id)
            ->call('loadHotspotProfiles')
            ->set('hotspot_profile', 'default')
            ->set('price', '0')
            ->set('currency', 'NGN')
            ->set('data_allowance_mb', '0')
            ->call('save')
            ->assertHasErrors(['data_allowance_mb']);

        $this->assertSame(0, Plan::query()->count());
    }

    public function test_unlimited_plan_ignores_a_stale_data_allowance_value(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $router = Router::factory()->create();
        $this->mockRouterProfiles(['unlimited']);

        $this->actingAs($admin);

        Livewire::test('pages::plans.create')
            ->set('name', 'Unlimited')
            ->set('router_id', (string) $router->id)
            ->call('loadHotspotProfiles')
            ->set('hotspot_profile', 'unlimited')
            ->set('price', '100')
            ->set('currency', 'NGN')
            ->set('data_allowance_mb', '0')
            ->set('is_unlimited', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Plan::firstOrFail()->data_allowance_bytes);
    }

    public function test_plan_speed_limits_are_copied_from_the_selected_profile(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $router = Router::factory()->create();
        $this->mockRouterProfiles(['default']);

        $this->actingAs($admin);

        Livewire::test('pages::plans.create')
            ->set('name', 'One-sided rate')
            ->set('router_id', (string) $router->id)
            ->call('loadHotspotProfiles')
            ->set('hotspot_profile', 'default')
            ->set('price', '100')
            ->set('currency', 'NGN')
            ->set('data_allowance_mb', '100')
            ->set('upload_speed_kbps', '512')
            ->set('download_speed_kbps', '2048')
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::firstOrFail();
        $this->assertNull($plan->upload_speed_kbps);
        $this->assertNull($plan->download_speed_kbps);
    }

    public function test_editing_a_plan_updates_its_router_profile_and_limits(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $router = Router::factory()->create();
        $plan = Plan::factory()->create(['router_id' => $router->id]);
        RouterPlanProfile::query()->create([
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'hotspot_profile' => 'old-profile',
        ]);
        $pendingVoucher = Voucher::factory()->create([
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'provisioning_status' => ProvisioningStatus::Pending,
            'upload_speed_kbps' => 512,
            'download_speed_kbps' => 2048,
            'provisioned_at' => null,
        ]);
        $failedVoucher = Voucher::factory()->create([
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'provisioning_status' => ProvisioningStatus::Failed,
            'upload_speed_kbps' => 512,
            'download_speed_kbps' => 2048,
            'provisioned_at' => null,
        ]);
        $provisionedVoucher = Voucher::factory()->create([
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'provisioning_status' => ProvisioningStatus::Provisioned,
            'upload_speed_kbps' => 128,
            'download_speed_kbps' => 256,
            'provisioned_at' => now(),
        ]);
        $this->mockRouterProfiles(['old-profile', 'new-profile'], '256k/1024k');

        $this->actingAs($admin);

        Livewire::test('pages::plans.edit', ['plan' => $plan])
            ->call('loadHotspotProfiles')
            ->set('hotspot_profile', 'new-profile')
            ->set('time_allowance_seconds', '61')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('router_plan_profiles', [
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'hotspot_profile' => 'new-profile',
        ]);
        $this->assertDatabaseMissing('router_plan_profiles', [
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'hotspot_profile' => 'old-profile',
        ]);
        $this->assertSame(61, $plan->fresh()->time_allowance_seconds);
        $this->assertSame(256, $plan->fresh()->upload_speed_kbps);
        $this->assertSame(1024, $plan->fresh()->download_speed_kbps);
        $this->assertSame('new-profile', $pendingVoucher->refresh()->hotspot_profile);
        $this->assertSame(256, $pendingVoucher->refresh()->upload_speed_kbps);
        $this->assertSame(1024, $pendingVoucher->refresh()->download_speed_kbps);
        $this->assertSame('new-profile', $failedVoucher->refresh()->hotspot_profile);
        $this->assertSame(256, $failedVoucher->refresh()->upload_speed_kbps);
        $this->assertSame(1024, $failedVoucher->refresh()->download_speed_kbps);
        $this->assertSame('default', $provisionedVoucher->refresh()->hotspot_profile);
        $this->assertSame(128, $provisionedVoucher->refresh()->upload_speed_kbps);
        $this->assertSame(256, $provisionedVoucher->refresh()->download_speed_kbps);
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

    /**
     * @param  array<int, string>  $names
     */
    private function mockRouterProfiles(array $names, ?string $rateLimit = null): void
    {
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->method('hotspotUserProfiles')->willReturn(array_map(
            fn (string $name): array => [
                'name' => $name,
                'rate_limit' => $rateLimit,
                'shared_users' => '1',
            ],
            $names,
        ));

        $this->app->instance(RouterGateway::class, $gateway);
    }
}
