<?php

namespace Tests\Feature\Routers;

use App\Contracts\RouterGateway;
use App\Enums\RouterJobStatus;
use App\Jobs\ConfigureRouterHotspotJob;
use App\Models\Router;
use App\Models\RouterJob;
use App\Models\User;
use App\Services\RouterJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class RouterHotspotServerConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_discovery_before_configuring_the_hotspot_server(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('configureHotspotServer')
            ->assertHasErrors(['hotspotServerConfiguration']);

        $this->assertDatabaseMissing('router_jobs', [
            'router_id' => $router->getKey(),
            'type' => 'configure_hotspot',
        ]);
    }

    public function test_it_queues_hotspot_server_configuration_after_discovery_and_confirmation(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $this->mockHotspotDiscoveryGateway();
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('discoverHotspotServerSettings')
            ->assertHasNoErrors()
            ->set('hotspotServerName', 'branch-hotspot')
            ->set('hotspotServerInterface', 'bridge-lan')
            ->set('hotspotServerPool', 'mmutech-lan-pool')
            ->set('hotspotServerProfile', 'default')
            ->call('openHotspotServerConfirmation')
            ->assertSet('confirmationDialog', 'hotspot_server')
            ->call('configureHotspotServer')
            ->assertSet('confirmationDialog', '')
            ->assertHasNoErrors();

        Bus::assertDispatched(ConfigureRouterHotspotJob::class);
        $job = RouterJob::query()
            ->where('router_id', $router->getKey())
            ->where('type', 'configure_hotspot')
            ->firstOrFail();

        $this->assertSame(RouterJobStatus::Queued, $job->status);
        $this->assertSame([
            'server_name' => 'branch-hotspot',
            'interface' => 'bridge-lan',
            'address_pool' => 'mmutech-lan-pool',
            'profile' => 'default',
        ], $job->payload);
    }

    public function test_it_generates_copyable_terminal_commands_without_queueing_a_job(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $router = Router::factory()->create();
        $this->mockHotspotDiscoveryGateway();
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('discoverHotspotServerSettings')
            ->call('generateHotspotServerCommands')
            ->assertHasNoErrors()
            ->assertSee('/ip hotspot add')
            ->assertSet('hotspotServerCommands.0', '/ip hotspot add name=mmutech-hotspot interface=bridge-lan address-pool=mmutech-lan-pool profile=default disabled=no');

        Bus::assertNotDispatched(ConfigureRouterHotspotJob::class);
        $this->assertDatabaseMissing('router_jobs', [
            'router_id' => $router->getKey(),
            'type' => 'configure_hotspot',
        ]);
    }

    public function test_the_job_stores_verification_and_succeeds_when_all_checks_pass(): void
    {
        $router = Router::factory()->create();
        $configuration = [
            'server_name' => 'mmutech-hotspot',
            'interface' => 'bridge-lan',
            'address_pool' => 'mmutech-lan-pool',
            'profile' => 'default',
        ];
        $checks = [
            'hotspot_server' => ['status' => 'passed', 'message' => 'Read back.'],
            'hotspot_server_unique' => ['status' => 'passed', 'message' => 'One server.'],
            'hotspot_server_profile' => ['status' => 'passed', 'message' => 'Profile exists.'],
            'hotspot_server_interface' => ['status' => 'passed', 'message' => 'Interface exists.'],
        ];
        $job = RouterJob::create([
            'router_id' => $router->getKey(),
            'type' => 'configure_hotspot',
            'status' => RouterJobStatus::Queued,
            'payload' => $configuration,
            'correlation_id' => 'correlation-id',
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('configureHotspotServer')
            ->with(
                $this->callback(fn (Router $requestedRouter): bool => $requestedRouter->is($router)),
                $configuration,
                $this->isType('string'),
            )
            ->willReturn($checks);

        (new ConfigureRouterHotspotJob($router->getKey(), $job->getKey(), 'correlation-id'))
            ->handle($gateway, app(RouterJobService::class));

        $job->refresh();
        $this->assertSame(RouterJobStatus::Succeeded, $job->status);
        $this->assertSame($configuration, $job->payload['configuration']);
        $this->assertSame($checks, $job->payload['verification']);
        $this->assertIsString($job->payload['verification_at']);
    }

    public function test_the_job_fails_when_read_back_verification_finds_a_mismatch(): void
    {
        $router = Router::factory()->create();
        $job = RouterJob::create([
            'router_id' => $router->getKey(),
            'type' => 'configure_hotspot',
            'status' => RouterJobStatus::Queued,
            'payload' => [
                'server_name' => 'mmutech-hotspot',
                'interface' => 'bridge-lan',
                'address_pool' => 'mmutech-lan-pool',
                'profile' => 'default',
            ],
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('configureHotspotServer')
            ->willReturn([
                'hotspot_server' => ['status' => 'failed', 'message' => 'Mismatch read back.'],
            ]);

        (new ConfigureRouterHotspotJob($router->getKey(), $job->getKey()))
            ->handle($gateway, app(RouterJobService::class));

        $job->refresh();
        $this->assertSame(RouterJobStatus::Failed, $job->status);
        $this->assertSame('configuration_verification', $job->error_category);
    }

    private function mockHotspotDiscoveryGateway(): void
    {
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->method('routerInterfaces')->willReturn([
            ['name' => 'bridge-lan', 'type' => 'bridge', 'running' => true, 'disabled' => false],
        ]);
        $gateway->method('ipPools')->willReturn([
            ['name' => 'mmutech-lan-pool', 'ranges' => '192.168.10.20-192.168.10.200'],
        ]);
        $gateway->method('hotspotProfiles')->willReturn([['name' => 'default']]);
        $gateway->method('hotspotServers')->willReturn([]);
        $this->app->instance(RouterGateway::class, $gateway);
    }
}
