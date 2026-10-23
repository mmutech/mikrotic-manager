<?php

namespace Tests\Feature\Jobs;

use App\Enums\RouterJobStatus;
use App\Enums\RouterStatus;
use App\Jobs\TestRouterConnectionJob;
use App\Models\Router;
use App\Models\RouterJob;
use App\Models\User;
use App\Services\RouterJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RouterJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_job_sets_queued_status_and_correlation_id(): void
    {
        $user = User::factory()->create();
        $router = Router::factory()->create();
        $service = app(RouterJobService::class);

        $job = $service->create($router, 'test_connection', $user, [], null, 'test-key-123');

        $this->assertEquals(RouterJobStatus::Queued, $job->status);
        $this->assertEquals('test-key-123', $job->idempotency_key);
        $this->assertNotNull($job->correlation_id);
        $this->assertEquals(3, $job->max_attempts);
    }

    public function test_pending_test_exists_prevents_duplicate(): void
    {
        $user = User::factory()->create();
        $router = Router::factory()->create();
        $service = app(RouterJobService::class);

        $this->assertFalse($service->pendingTestExists($router));

        $service->create($router, 'test_connection', $user, [], null, 'router-test:'.$router->getKey());

        $this->assertTrue($service->pendingTestExists($router));
    }

    public function test_job_can_be_marked_succeeded_or_failed(): void
    {
        $user = User::factory()->create();
        $router = Router::factory()->create();
        $service = app(RouterJobService::class);
        $job = $service->create($router, 'test_connection', $user);

        $service->markRunning($job);
        $job->refresh();
        $this->assertEquals(RouterJobStatus::Running, $job->status);
        $this->assertEquals(1, $job->attempts);

        $service->markSucceeded($job);
        $job->refresh();
        $this->assertEquals(RouterJobStatus::Succeeded, $job->status);
        $this->assertNotNull($job->finished_at);
    }
}
