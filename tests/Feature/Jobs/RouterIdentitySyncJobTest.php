<?php

namespace Tests\Feature\Jobs;

use App\Contracts\RouterGateway;
use App\Jobs\SyncRouterIdentityJob;
use App\Models\Router;
use App\Models\User;
use App\Services\RouterJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterIdentitySyncJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_the_current_router_name_and_marks_the_job_succeeded(): void
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

        $job = new SyncRouterIdentityJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        );

        $job->handle($gateway, app(RouterJobService::class));

        $this->assertSame('Branch Router', $router->refresh()->identity);
        $this->assertSame('succeeded', $routerJob->refresh()->status->value);
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
}
