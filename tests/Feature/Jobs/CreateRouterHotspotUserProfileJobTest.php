<?php

namespace Tests\Feature\Jobs;

use App\Contracts\RouterGateway;
use App\Jobs\CreateRouterHotspotUserProfileJob;
use App\Models\Router;
use App\Models\RouterJob;
use App\Models\User;
use App\Services\RouterJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateRouterHotspotUserProfileJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_router_level_profile_and_succeeds_the_tracked_job(): void
    {
        $router = Router::factory()->create();
        $routerJob = $this->createRouterJob($router, [
            'profile_name' => 'staff-access',
            'rate_limit' => '512k/2048k',
            'shared_users' => 2,
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('createHotspotUserProfile')
            ->with(
                $this->callback(fn (Router $configuredRouter): bool => $configuredRouter->is($router)),
                'staff-access',
                '512k/2048k',
                2,
                $routerJob->correlation_id,
            );

        (new CreateRouterHotspotUserProfileJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        ))->handle($gateway, app(RouterJobService::class));

        $this->assertSame('succeeded', $routerJob->refresh()->status->value);
        $this->assertDatabaseMissing('router_plan_profiles', ['router_id' => $router->getKey()]);
    }

    public function test_it_fails_invalid_profile_settings_without_contacting_the_router(): void
    {
        $router = Router::factory()->create();
        $routerJob = $this->createRouterJob($router, [
            'profile_name' => 'invalid name',
            'rate_limit' => '512k',
            'shared_users' => 0,
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->never())->method('createHotspotUserProfile');

        (new CreateRouterHotspotUserProfileJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        ))->handle($gateway, app(RouterJobService::class));

        $routerJob->refresh();
        $this->assertSame('failed', $routerJob->status->value);
        $this->assertSame('Hotspot user profile configuration is invalid or incomplete.', $routerJob->error_message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createRouterJob(Router $router, array $payload): RouterJob
    {
        return app(RouterJobService::class)->create(
            router: $router,
            type: 'create_hotspot_user_profile',
            actor: User::factory()->create(),
            payload: $payload,
        );
    }
}
