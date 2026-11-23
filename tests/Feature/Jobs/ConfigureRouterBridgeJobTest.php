<?php

namespace Tests\Feature\Jobs;

use App\Contracts\RouterGateway;
use App\Jobs\ConfigureRouterBridgeJob;
use App\Models\Router;
use App\Models\User;
use App\Services\RouterJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigureRouterBridgeJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_configures_the_requested_bridge_and_marks_the_job_succeeded(): void
    {
        $router = Router::factory()->create();
        $actor = User::factory()->create();
        $routerJob = app(RouterJobService::class)->create(
            router: $router,
            type: 'configure_bridge',
            actor: $actor,
            payload: [
                'bridge_name' => 'bridge-lan',
                'interfaces' => ['ether2', 'ether3'],
                'management_impact_confirmed' => true,
            ],
        );

        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('configureBridge')
            ->with(
                $this->callback(fn (Router $configuredRouter): bool => $configuredRouter->is($router)),
                'bridge-lan',
                ['ether2', 'ether3'],
                $routerJob->correlation_id,
            );

        $job = new ConfigureRouterBridgeJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        );

        $job->handle($gateway, app(RouterJobService::class));

        $this->assertSame('succeeded', $routerJob->refresh()->status->value);
    }

    public function test_it_refuses_to_change_the_router_without_management_impact_confirmation(): void
    {
        $router = Router::factory()->create();
        $actor = User::factory()->create();
        $routerJob = app(RouterJobService::class)->create(
            router: $router,
            type: 'configure_bridge',
            actor: $actor,
            payload: [
                'bridge_name' => 'bridge-lan',
                'interfaces' => ['ether2'],
                'management_impact_confirmed' => false,
            ],
        );

        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->never())->method('configureBridge');

        $job = new ConfigureRouterBridgeJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
        );

        $job->handle($gateway, app(RouterJobService::class));

        $this->assertSame('failed', $routerJob->refresh()->status->value);
        $this->assertStringContainsString('confirmation', $routerJob->error_message);
    }
}
