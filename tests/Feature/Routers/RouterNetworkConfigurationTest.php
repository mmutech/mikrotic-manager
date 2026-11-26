<?php

namespace Tests\Feature\Routers;

use App\Contracts\RouterGateway;
use App\Jobs\ConfigureRouterNetworkJob;
use App\Jobs\CreateRouterHotspotUserProfileJob;
use App\Jobs\EnableRouterHotspotCookieLoginJob;
use App\Jobs\UpdateRouterHotspotUserProfileJob;
use App\Models\Router;
use App\Models\RouterJob;
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

    public function test_it_generates_copyable_network_commands_without_queueing_a_router_job(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $commands = ['/ip address add address=192.168.10.1/24 interface=bridge-lan'];
        $configurator = $this->createMock(MikrotikRouterConfigurator::class);
        $configurator->expects($this->once())
            ->method('terminalCommands')
            ->with(
                $this->callback(fn (Router $requestedRouter): bool => $requestedRouter->is($router)),
                $this->callback(fn (array $configuration): bool => $configuration === [
                    'bridge_name' => 'bridge-lan',
                    'wan_interface' => 'ether1',
                    'lan_address' => '192.168.10.1/24',
                    'pool_start' => '192.168.10.20',
                    'pool_end' => '192.168.10.200',
                    'dns_servers' => ['1.1.1.1'],
                    'management_cidrs' => ['10.20.1.15/24'],
                    'management_impact_confirmed' => true,
                ]),
            )
            ->willReturn($commands);
        $this->app->instance(MikrotikRouterConfigurator::class, $configurator);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->set('lanInterfacesDiscovered', true)
            ->set('lanInterfaces', [
                ['name' => 'ether1', 'running' => true, 'disabled' => false, 'bridge' => null],
                ['name' => 'ether2', 'running' => true, 'disabled' => false, 'bridge' => 'bridge-lan'],
            ])
            ->set('networkBridgeName', 'bridge-lan')
            ->set('wanInterface', 'ether1')
            ->set('lanAddress', '192.168.10.1/24')
            ->set('poolStart', '192.168.10.20')
            ->set('poolEnd', '192.168.10.200')
            ->set('dnsServers', '1.1.1.1')
            ->set('managementCidrs', '10.20.1.15/24')
            ->set('networkImpactConfirmed', true)
            ->call('generateNetworkCommands')
            ->assertHasNoErrors()
            ->assertSee($commands[0])
            ->assertSet('networkCommands', $commands);

        Bus::assertNotDispatched(ConfigureRouterNetworkJob::class);
        $this->assertDatabaseMissing('router_jobs', [
            'router_id' => $router->getKey(),
            'type' => 'configure_network',
        ]);
    }

    public function test_it_discovers_current_dhcp_nat_and_firewall_configuration(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create(['identity' => 'Branch-HQ']);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('networkConfiguration')
            ->with(
                $this->callback(fn (Router $requestedRouter): bool => $requestedRouter->is($router)),
                $this->isType('string'),
            )
            ->willReturn([
                'addresses' => [[
                    'id' => '*1',
                    'title' => '192.168.88.1/24',
                    'detail' => 'bridge-lan · enabled',
                    'comment' => 'MMUTech managed LAN address',
                    'disabled' => false,
                    'managed' => true,
                ]],
                'pools' => [],
                'dhcp_networks' => [],
                'dhcp_servers' => [[
                    'id' => '*2',
                    'title' => 'mmutech-lan-dhcp',
                    'detail' => 'bridge-lan · Branch-HQ · enabled',
                    'comment' => 'MMUTech managed LAN DHCP server',
                    'disabled' => false,
                    'managed' => true,
                ]],
                'nat_rules' => [[
                    'id' => '*3',
                    'title' => 'srcnat → masquerade',
                    'detail' => '192.168.88.0/24 · ether1 · enabled',
                    'comment' => 'MMUTech managed LAN masquerade',
                    'disabled' => false,
                    'managed' => true,
                ]],
                'firewall_rules' => [],
            ]);
        $this->app->instance(RouterGateway::class, $gateway);

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('discoverNetworkConfiguration')
            ->assertHasNoErrors()
            ->assertSet('networkConfigurationDiscovered', true)
            ->assertSee('192.168.88.1/24')
            ->assertSee('mmutech-lan-dhcp')
            ->assertSee('srcnat → masquerade')
            ->assertSee('MMUTech managed');
    }

    public function test_it_hides_and_reveals_the_network_form_on_toggle(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->assertSet('networkFormVisible', false)
            ->assertDontSee('Load managed values')
            ->assertDontSee('LAN gateway address/prefix')
            ->assertSee('Show form fields')
            ->call('toggleNetworkForm')
            ->assertSet('networkFormVisible', true)
            ->assertSee('Hide form fields')
            ->assertSee('LAN gateway address/prefix')
            ->call('toggleNetworkForm')
            ->assertSet('networkFormVisible', false)
            ->assertDontSee('LAN gateway address/prefix');
    }

    public function test_it_auto_detects_management_source_cidrs_without_queueing_a_router_job(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('managementSourceCidrs')
            ->with(
                $this->callback(fn (Router $requestedRouter): bool => $requestedRouter->is($router)),
                $this->isType('string'),
            )
            ->willReturn(['10.20.1.0/24', '203.0.113.25/32']);
        $this->app->instance(RouterGateway::class, $gateway);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->set('networkImpactConfirmed', true)
            ->call('discoverManagementSourceCidrs')
            ->assertHasNoErrors()
            ->assertSet('managementCidrs', "10.20.1.0/24\n203.0.113.25/32")
            ->assertSet('networkImpactConfirmed', false)
            ->assertSet('networkCommands', [])
            ->assertSee("Last auto-detect resolved this server's source network to 2 CIDR(s)");

        Bus::assertNotDispatched(ConfigureRouterNetworkJob::class);
        $this->assertDatabaseMissing('router_jobs', [
            'router_id' => $router->getKey(),
            'type' => 'configure_network',
        ]);
    }

    public function test_it_hides_and_reveals_the_hotspot_user_profile_form_on_toggle(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->assertSet('hotspotProfileFormVisible', false)
            ->assertSee('Show profile form')
            ->assertDontSee('Queue profile creation')
            ->call('toggleHotspotProfileForm')
            ->assertSet('hotspotProfileFormVisible', true)
            ->assertSee('Hide profile form')
            ->assertSee('Queue profile creation')
            ->call('toggleHotspotProfileForm')
            ->assertSet('hotspotProfileFormVisible', false)
            ->assertDontSee('Queue profile creation');
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

    public function test_it_queues_cookie_login_for_router_hotspot_server_profiles(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('openCookieLoginConfirmation')
            ->assertSet('confirmationDialog', 'cookie_login')
            ->call('enableHotspotCookieLogin')
            ->assertSet('confirmationDialog', '')
            ->assertDispatched('app-alert')
            ->assertHasNoErrors();

        Bus::assertDispatched(EnableRouterHotspotCookieLoginJob::class);
        $this->assertDatabaseHas('router_jobs', [
            'router_id' => $router->getKey(),
            'type' => 'enable_hotspot_cookie_login',
            'status' => 'queued',
        ]);
    }

    public function test_it_queues_a_profile_with_no_rate_limit_when_both_rates_are_blank(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->set('hotspotProfileName', 'guest-access')
            ->set('hotspotProfileSharedUsers', '2')
            ->call('createRouterHotspotUserProfile')
            ->assertHasNoErrors();

        $routerJob = RouterJob::query()
            ->where('router_id', $router->getKey())
            ->where('type', 'create_hotspot_user_profile')
            ->firstOrFail();

        $this->assertNull($routerJob->payload['rate_limit']);
        $this->assertSame(2, $routerJob->payload['shared_users']);
        Bus::assertDispatched(CreateRouterHotspotUserProfileJob::class);
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
                [
                    'id' => '*1',
                    'name' => 'staff-access',
                    'rate_limit' => '512k/2048k',
                    'shared_users' => '2',
                ],
            ]);
        $this->app->instance(RouterGateway::class, $gateway);

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('discoverRouterHotspotUserProfiles')
            ->assertHasNoErrors()
            ->assertSee('staff-access')
            ->assertSee('512k/2048k')
            ->assertSee('Edit profile');
    }

    public function test_it_loads_a_discovered_profile_into_the_form_for_editing(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->method('hotspotUserProfiles')->willReturn([
            [
                'id' => '*1',
                'name' => 'staff-access',
                'rate_limit' => '512k/2048k',
                'shared_users' => '2',
            ],
        ]);
        $this->app->instance(RouterGateway::class, $gateway);

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('discoverRouterHotspotUserProfiles')
            ->call('editRouterHotspotUserProfile', '*1')
            ->assertSet('hotspotProfileFormVisible', true)
            ->assertSet('editingHotspotProfileId', '*1')
            ->assertSet('editingHotspotProfileName', 'staff-access')
            ->assertSet('hotspotProfileName', 'staff-access')
            ->assertSet('hotspotProfileUploadSpeedKbps', '512')
            ->assertSet('hotspotProfileDownloadSpeedKbps', '2048')
            ->assertSet('hotspotProfileSharedUsers', '2')
            ->assertSet('hotspotProfileExpectedRateLimit', '512k/2048k')
            ->assertSet('hotspotProfileExpectedSharedUsers', '2')
            ->assertSee('Queue profile update');
    }

    public function test_it_cancels_editing_a_discovered_profile(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->method('hotspotUserProfiles')->willReturn([
            [
                'id' => '*1',
                'name' => 'staff-access',
                'rate_limit' => '512k/2048k',
                'shared_users' => '2',
            ],
        ]);
        $this->app->instance(RouterGateway::class, $gateway);

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('discoverRouterHotspotUserProfiles')
            ->call('editRouterHotspotUserProfile', '*1')
            ->assertSet('editingHotspotProfileId', '*1')
            ->call('resetHotspotProfileForm')
            ->assertSet('editingHotspotProfileId', null)
            ->assertSet('editingHotspotProfileName', null)
            ->assertSet('hotspotProfileName', '')
            ->assertSet('hotspotProfileUploadSpeedKbps', '')
            ->assertSet('hotspotProfileDownloadSpeedKbps', '')
            ->assertSet('hotspotProfileSharedUsers', '1')
            ->assertSee('Queue profile creation')
            ->assertDontSee('Cancel edit');
    }

    public function test_it_queues_a_profile_update_with_discovered_values_for_verification(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->method('hotspotUserProfiles')->willReturn([
            [
                'id' => '*1',
                'name' => 'staff-access',
                'rate_limit' => '512k/2048k',
                'shared_users' => '2',
            ],
        ]);
        $this->app->instance(RouterGateway::class, $gateway);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('discoverRouterHotspotUserProfiles')
            ->call('editRouterHotspotUserProfile', '*1')
            ->set('hotspotProfileUploadSpeedKbps', '1024')
            ->set('hotspotProfileDownloadSpeedKbps', '4096')
            ->set('hotspotProfileSharedUsers', '3')
            ->call('saveRouterHotspotUserProfile')
            ->assertHasNoErrors()
            ->assertDispatched('app-alert');

        Bus::assertDispatched(UpdateRouterHotspotUserProfileJob::class);
        Bus::assertNotDispatched(CreateRouterHotspotUserProfileJob::class);

        $routerJob = RouterJob::query()
            ->where('router_id', $router->getKey())
            ->where('type', 'update_hotspot_user_profile')
            ->firstOrFail();

        $this->assertSame([
            'profile_id' => '*1',
            'profile_name' => 'staff-access',
            'expected_rate_limit' => '512k/2048k',
            'expected_shared_users' => 2,
            'rate_limit' => '1024k/4096k',
            'shared_users' => 3,
        ], $routerJob->payload);
    }
}
