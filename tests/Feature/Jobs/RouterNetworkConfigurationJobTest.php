<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ConfigureRouterNetworkJob;
use App\Jobs\VerifyRouterNetworkJob;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikRouterConfigurator;
use App\Services\RouterJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterNetworkConfigurationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_the_normalized_configuration_and_readback_report(): void
    {
        $router = Router::factory()->create();
        $routerJob = app(RouterJobService::class)->create(
            router: $router,
            type: 'configure_network',
            actor: User::factory()->create(),
            payload: $this->configuration(),
        );
        $report = [
            'firewall' => ['status' => 'passed', 'message' => 'Rules are present.'],
            'firewall_precedence' => ['status' => 'warning', 'message' => 'Existing rules precede the policy.'],
        ];
        $configurator = $this->createMock(MikrotikRouterConfigurator::class);
        $configurator->expects($this->once())
            ->method('normalize')
            ->with($this->configuration())
            ->willReturn($this->configuration());
        $configurator->expects($this->once())
            ->method('configure')
            ->with(
                $this->callback(fn (Router $configuredRouter): bool => $configuredRouter->is($router)),
                $this->configuration(),
                $routerJob->correlation_id,
            )
            ->willReturn($report);

        (new ConfigureRouterNetworkJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        ))->handle($configurator, app(RouterJobService::class));

        $routerJob->refresh();
        $this->assertSame('succeeded', $routerJob->status->value);
        $this->assertSame($this->configuration(), $routerJob->payload['configuration']);
        $this->assertSame($report, $routerJob->payload['verification']);
        $this->assertNotEmpty($routerJob->payload['verification_at']);
    }

    public function test_it_marks_configuration_failed_when_readback_contains_a_mismatch(): void
    {
        $router = Router::factory()->create();
        $routerJob = app(RouterJobService::class)->create(
            router: $router,
            type: 'configure_network',
            actor: User::factory()->create(),
            payload: $this->configuration(),
        );
        $report = [
            'dhcp_server' => ['status' => 'failed', 'message' => 'The DHCP server is missing.'],
        ];
        $configurator = $this->createMock(MikrotikRouterConfigurator::class);
        $configurator->method('normalize')->willReturn($this->configuration());
        $configurator->method('configure')->willReturn($report);

        (new ConfigureRouterNetworkJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
        ))->handle($configurator, app(RouterJobService::class));

        $routerJob->refresh();
        $this->assertSame('failed', $routerJob->status->value);
        $this->assertSame($report, $routerJob->payload['verification']);
        $this->assertSame('configuration_verification', $routerJob->error_category);
    }

    public function test_it_queues_a_read_only_verification_against_the_saved_configuration(): void
    {
        $router = Router::factory()->create();
        $routerJob = app(RouterJobService::class)->create(
            router: $router,
            type: 'verify_network',
            actor: User::factory()->create(),
            payload: [
                'configuration' => $this->configuration(),
                'source_job_uuid' => 'saved-configuration',
            ],
        );
        $report = [
            'lan_address' => ['status' => 'passed', 'message' => 'The LAN address is present.'],
        ];
        $configurator = $this->createMock(MikrotikRouterConfigurator::class);
        $configurator->expects($this->once())
            ->method('normalize')
            ->with($this->configuration())
            ->willReturn($this->configuration());
        $configurator->expects($this->once())
            ->method('verify')
            ->with(
                $this->callback(fn (Router $configuredRouter): bool => $configuredRouter->is($router)),
                $this->configuration(),
                $routerJob->correlation_id,
            )
            ->willReturn($report);
        $configurator->expects($this->never())->method('configure');

        (new VerifyRouterNetworkJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        ))->handle($configurator, app(RouterJobService::class));

        $routerJob->refresh();
        $this->assertSame('succeeded', $routerJob->status->value);
        $this->assertSame($report, $routerJob->payload['verification']);
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        return [
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
    }
}
