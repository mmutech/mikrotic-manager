<?php

namespace Tests\Feature\Jobs;

use App\Contracts\RouterGateway;
use App\Jobs\UpdateRouterHotspotUserProfileJob;
use App\Models\Router;
use App\Models\RouterJob;
use App\Models\User;
use App\Services\RouterJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateRouterHotspotUserProfileJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_a_profile_and_succeeds_the_tracked_job(): void
    {
        $router = Router::factory()->create();
        $routerJob = $this->createRouterJob($router, [
            'profile_id' => '*1',
            'profile_name' => 'staff-access',
            'expected_rate_limit' => '512k/2048k',
            'expected_shared_users' => 1,
            'rate_limit' => '1024k/4096k',
            'shared_users' => 2,
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('updateHotspotUserProfile')
            ->with(
                $this->callback(fn (Router $configuredRouter): bool => $configuredRouter->is($router)),
                '*1',
                'staff-access',
                '512k/2048k',
                1,
                '1024k/4096k',
                2,
                $routerJob->correlation_id,
            );

        (new UpdateRouterHotspotUserProfileJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        ))->handle($gateway, app(RouterJobService::class));

        $this->assertSame('succeeded', $routerJob->refresh()->status->value);
    }

    public function test_it_treats_an_empty_optional_rate_limit_as_unlimited(): void
    {
        $router = Router::factory()->create();
        $routerJob = $this->createRouterJob($router, [
            'profile_id' => '*3',
            'profile_name' => 'guest-access',
            'expected_rate_limit' => '512k/2048k',
            'expected_shared_users' => 1,
            'rate_limit' => '',
            'shared_users' => 1,
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('updateHotspotUserProfile')
            ->with(
                $this->callback(fn (Router $configuredRouter): bool => $configuredRouter->is($router)),
                '*3',
                'guest-access',
                '512k/2048k',
                1,
                null,
                1,
                $routerJob->correlation_id,
            );

        (new UpdateRouterHotspotUserProfileJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        ))->handle($gateway, app(RouterJobService::class));

        $this->assertSame('succeeded', $routerJob->refresh()->status->value);
    }

    public function test_it_fails_invalid_profile_updates_without_contacting_the_router(): void
    {
        $router = Router::factory()->create();
        $routerJob = $this->createRouterJob($router, [
            'profile_id' => 'invalid',
            'profile_name' => 'invalid name',
            'expected_rate_limit' => '512k',
            'expected_shared_users' => 0,
            'rate_limit' => '512k',
            'shared_users' => 0,
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->never())->method('updateHotspotUserProfile');

        (new UpdateRouterHotspotUserProfileJob(
            routerId: $router->getKey(),
            routerJobId: $routerJob->getKey(),
            correlationId: $routerJob->correlation_id,
        ))->handle($gateway, app(RouterJobService::class));

        $routerJob->refresh();
        $this->assertSame('failed', $routerJob->status->value);
        $this->assertSame('Hotspot user profile update configuration is invalid or incomplete.', $routerJob->error_message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createRouterJob(Router $router, array $payload): RouterJob
    {
        return app(RouterJobService::class)->create(
            router: $router,
            type: 'update_hotspot_user_profile',
            actor: User::factory()->create(),
            payload: $payload,
        );
    }
}
