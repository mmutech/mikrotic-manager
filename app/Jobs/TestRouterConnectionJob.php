<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\RouterJob;
use App\Models\User;
use App\Services\RouterHealthService;
use App\Services\RouterJobService;
use App\Support\CorrelationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class TestRouterConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 20, 60];

    public int $timeout = 40;

    public function __construct(
        public readonly int $routerId,
        public readonly ?int $routerJobId = null,
        public readonly ?int $actorId = null,
        public readonly ?string $correlationId = null,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('router-test:'.$this->routerId))->expireAfter(60)->releaseAfter(10),
        ];
    }

    public function handle(RouterHealthService $health, RouterJobService $jobs): void
    {
        $correlation = app(CorrelationContext::class);
        $correlationId = $correlation->set($this->correlationId);

        $router = Router::withTrashed()->find($this->routerId);

        if ($router === null || $router->trashed()) {
            return;
        }

        $job = $this->routerJobId !== null ? RouterJob::find($this->routerJobId) : null;

        if ($job !== null && ! $job->status->isPending()) {
            return;
        }

        if ($job !== null) {
            $jobs->markRunning($job);
        }

        $actor = $this->actorId !== null ? User::find($this->actorId) : null;

        try {
            $check = $health->check(
                $router,
                actor: $actor,
                correlationId: $correlationId,
                audit: $actor !== null,
            );

            if ($job !== null) {
                if ($check->status->isSuccess()) {
                    $jobs->markSucceeded($job);
                } else {
                    $jobs->markFailed(
                        $job,
                        'Router probe returned '.$check->status->label().'.',
                        $check->error_category,
                    );
                }
            }
        } catch (Throwable $exception) {
            if ($job !== null) {
                $jobs->markFailed($job, $exception);
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->routerJobId === null) {
            return;
        }

        $job = RouterJob::find($this->routerJobId);

        if ($job !== null && ! $job->isSettled()) {
            app(RouterJobService::class)->markFailed($job, $exception ?? 'Queue job failed.');
        }
    }
}
