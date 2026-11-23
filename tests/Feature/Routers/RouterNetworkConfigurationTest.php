<?php

namespace Tests\Feature\Routers;

use App\Contracts\RouterGateway;
use App\Jobs\ConfigureRouterNetworkJob;
use App\Jobs\CreateRouterHotspotUserProfileJob;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikRouterConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class RouterNetworkConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_explicit_site_network_values_and_impact_confirmation(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('configureNetwork')
            ->assertHasErrors([
                'networkBridgeName',
                'wanInterface',
                'lanAddress',
                'poolStart',
                'poolEnd',
                'dnsServers',
                'managementCidrs',
                'networkImpactConfirmed',
            ]);

        $this->assertDatabaseMissing('router_jobs', ['router_id' => $router->getKey(), 'type' => 'configure_network']);
    }

    public function test_it_queues_network_configuration_only_after_discovery_and_confirmation(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $normalized = [
            'bridge_name' => 'bridge-lan',
            'wan_interface' => 'ether1',
            'lan_address' => '192.168.10.1/24',
            'lan_gateway' => '192.168.10.1',
            'network_cidr' => '192.168.10.0/24',
            'pool_start' => '192.168.10.20',
            'pool_end' => '192.168.10.200',
            'pool_range' => '192.168.10.20-192.168.10.200',
            'dns_servers' => ['1.1.1.1'],
            'management_cidrs' => ['10.20.1.0/24'],
            'management_impact_confirmed' => true,
        ];
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('ethernetInterfaces')
            ->willReturn([
                ['name' => 'ether1', 'running' => true, 'disabled' => false, 'bridge' => null],
                ['name' => 'ether2', 'running' => true, 'disabled' => false, 'bridge' => 'bridge-lan'],
            ]);
        $this->app->instance(RouterGateway::class, $gateway);

        $configurator = $this->createMock(MikrotikRouterConfigurator::class);
        $configurator->expects($this->once())
            ->method('normalize')
            ->willReturn($normalized);
        $this->app->instance(MikrotikRouterConfigurator::class, $configurator);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->set('networkBridgeName', 'bridge-lan')
            ->set('wanInterface', 'ether1')
            ->set('lanAddress', '192.168.10.1/24')
            ->set('poolStart', '192.168.10.20')
            ->set('poolEnd', '192.168.10.200')
            ->set('dnsServers', '1.1.1.1')
            ->set('managementCidrs', '10.20.1.15/24')
            ->set('networkImpactConfirmed', true)
            ->call('configureNetwork')
            ->assertHasNoErrors();

        Bus::assertDispatched(ConfigureRouterNetworkJob::class);
        $this->assertDatabaseHas('router_jobs', [
            'router_id' => $router->getKey(),
            'type' => 'configure_network',
            'status' => 'queued',
        ]);
    }

    public function test_it_queues_a_standalone_router_hotspot_user_profile(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->set('hotspotProfileName', 'staff-access')
            ->set('hotspotProfileUploadSpeedKbps', '512')
            ->set('hotspotProfileDownloadSpeedKbps', '2048')
            ->set('hotspotProfileSharedUsers', '2')
            ->call('createRouterHotspotUserProfile')
            ->assertHasNoErrors();

        Bus::assertDispatched(CreateRouterHotspotUserProfileJob::class);
        $this->assertDatabaseHas('router_jobs', [
            'router_id' => $router->getKey(),
            'type' => 'create_hotspot_user_profile',
            'status' => 'queued',
            'related_id' => null,
        ]);
    }

    public function test_it_requires_both_rate_limit_values_when_one_is_set(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->set('hotspotProfileName', 'staff-access')
            ->set('hotspotProfileUploadSpeedKbps', '512')
            ->call('createRouterHotspotUserProfile')
            ->assertHasErrors(['hotspotProfileDownloadSpeedKbps']);

        $this->assertDatabaseMissing('router_jobs', [
            'router_id' => $router->getKey(),
            'type' => 'create_hotspot_user_profile',
        ]);
    }

    public function test_it_refreshes_the_router_hotspot_user_profile_list(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('hotspotUserProfiles')
            ->with(
                $this->callback(fn (Router $requestedRouter): bool => $requestedRouter->is($router)),
                $this->isType('string'),
            )
            ->willReturn([
                ['name' => 'staff-access', 'rate_limit' => '512k/2048k', 'shared_users' => '2'],
            ]);
        $this->app->instance(RouterGateway::class, $gateway);

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('discoverRouterHotspotUserProfiles')
            ->assertHasNoErrors()
            ->assertSee('staff-access')
            ->assertSee('512k/2048k');
    }
}
