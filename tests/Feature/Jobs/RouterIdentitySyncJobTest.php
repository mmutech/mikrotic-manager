<?php

namespace Tests\Feature\Jobs;

use App\Contracts\RouterGateway;
use App\Data\RouterProbeResult;
use App\Jobs\SyncRouterIdentityJob;
use App\Models\Router;
use App\Models\RouterJob;
use App\Models\User;
use App\Services\RouterJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterIdentitySyncJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_the_configured_identity_and_verifies_it_by_read_back(): void
    {
        $router = Router::factory()->create(['name' => 'Branch Router']);
        $actor = User::factory()->create();
        $routerJob = app(RouterJobService::class)->create(
            $router,
            'sync_identity',
            $actor,
            payload: ['identity' => 'JEDC-HQ'],
        );

        $gateway = \Mockery::mock(RouterGateway::class);
        $gateway->shouldReceive('setIdentity')
            ->once()
            ->with(
                \Mockery::on(fn (Router $syncedRouter): bool => $syncedRouter->is($router)),
                'JEDC-HQ',
                $routerJob->correlation_id,
            );
        $gateway->shouldReceive('probe')
            ->once()
            ->andReturn($this->probeResult('JEDC-HQ'));

        $this->dispatchJob($router, $routerJob, $gateway);

        $router->refresh();
        $routerJob->refresh();

        $this->assertSame('JEDC-HQ', $router->identity);
        $this->assertSame('succeeded', $routerJob->status->value);
        $this->assertSame([
            'status' => 'passed',
            'expected' => 'JEDC-HQ',
            'actual' => 'JEDC-HQ',
        ], array_intersect_key($routerJob->payload['verification'], array_flip([
            'status', 'expected', 'actual',
        ])));
    }

    public function test_it_falls_back_to_the_router_name_when_no_identity_was_queued(): void
    {
        $router = Router::factory()->create(['name' => 'Branch Router']);
        $actor = User::factory()->create();
        $routerJob = app(RouterJobService::class)->create($router, 'sync_identity', $actor);

        $gateway = \Mockery::mock(RouterGateway::class);
        $gateway->shouldReceive('setIdentity')
            ->once()
            ->with(
                \Mockery::on(fn (Router $syncedRouter): bool => $syncedRouter->is($router)),
                'Branch Router',
                $routerJob->correlation_id,
            );
        $gateway->shouldReceive('probe')
            ->once()
            ->andReturn($this->probeResult('Branch Router'));

        $this->dispatchJob($router, $routerJob, $gateway);

        $this->assertSame('Branch Router', $router->refresh()->identity);
        $this->assertSame('succeeded', $routerJob->refresh()->status->value);
    }

    public function test_it_fails_the_job_when_read_back_does_not_match_the_configured_identity(): void
    {
        $router = Router::factory()->create(['name' => 'Branch Router']);
        $actor = User::factory()->create();
        $routerJob = app(RouterJobService::class)->create(
            $router,
            'sync_identity',
            $actor,
            payload: ['identity' => 'JEDC-HQ'],
        );

        $gateway = \Mockery::mock(RouterGateway::class);
        $gateway->shouldReceive('setIdentity')->once();
        $gateway->shouldReceive('probe')
            ->once()
            ->andReturn($this->probeResult('Old Identity'));

        $this->dispatchJob($router, $routerJob, $gateway);

        $routerJob->refresh();

        $this->assertSame('failed', $routerJob->status->value);
        $this->assertSame('identity_verification', $routerJob->error_category);
        $this->assertSame('failed', $routerJob->payload['verification']['status']);
        $this->assertSame('Old Identity', $router->refresh()->identity);
    }

    public function test_it_marks_the_job_failed_when_the_router_is_no_longer_available(): void
    {
        $router = Router::factory()->create();
        $actor = User::factory()->create();
        $routerJob = app(RouterJobService::class)->create($router, 'sync_identity', $actor);

        $job = new SyncRouterIdentityJob(
            routerId: $router->getKey() + 1,
            routerJobId: $routerJob->getKey(),
        );

        $job->handle($this->createMock(RouterGateway::class), app(RouterJobService::class));

        $this->assertSame('failed', $routerJob->refresh()->status->value);
        $this->assertSame(
            'Router is not available for identity synchronization.',
            $routerJob->error_message,
        );
    }

    private function dispatchJob(Router $router, RouterJob $routerJob, RouterGateway $gateway): void
    {
        $job = new SyncRouterIdentityJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        );

        $job->handle($gateway, app(RouterJobService::class));
    }

    private function probeResult(string $identity): RouterProbeResult
    {
        return new RouterProbeResult(
            identity: $identity,
            version: '7.16.2',
            boardName: 'RB5009UG+S+IN',
            uptimeSeconds: 3600,
            cpuLoad: 5,
            freeMemory: 100_000_000,
            totalMemory: 500_000_000,
            latencyMs: 12,
            resources: [],
        );
    }
}
