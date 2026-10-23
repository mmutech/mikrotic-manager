<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\RouterJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncRouterHealthJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-router-health'))->expireAfter(240)];
    }

    public function handle(RouterJobService $jobs): void
    {
        Router::query()
            ->monitoring()
            ->orderBy('id')
            ->chunkById(100, function ($routers) use ($jobs): void {
                foreach ($routers as $router) {
                    if ($jobs->pendingTestExists($router)) {
                        continue;
                    }

                    $job = $jobs->create($router, 'test_connection');

                    TestRouterConnectionJob::dispatch(
                        routerId: $router->getKey(),
                        routerJobId: $job->getKey(),
                        actorId: null,
                        correlationId: $job->correlation_id,
                    )->afterCommit();
                }
            });
    }
}
