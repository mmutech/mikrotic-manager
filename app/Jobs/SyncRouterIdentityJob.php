<?php

namespace App\Jobs;

use App\Contracts\RouterGateway;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Models\RouterJob;
use App\Services\RouterJobService;
use App\Support\CorrelationContext;
use App\Support\RouterIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SyncRouterIdentityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 20, 60];

    public int $timeout = 40;

    public function __construct(
        public readonly int $routerId,
        public readonly int $routerJobId,
        public readonly ?string $correlationId = null,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('router-management:'.$this->routerId))->expireAfter(60)->releaseAfter(10),
        ];
    }

    public function handle(RouterGateway $gateway, RouterJobService $jobs): void
    {
        $correlationId = app(CorrelationContext::class)->set($this->correlationId);
        $job = RouterJob::query()
            ->whereKey($this->routerJobId)
            ->where('router_id', $this->routerId)
            ->where('type', 'sync_identity')
            ->first();

        if ($job === null || ! $job->status->isPending()) {
            return;
        }

        $router = Router::find($this->routerId);

        if ($router === null) {
            $jobs->markFailed($job, 'Router is not available for identity synchronization.');

            return;
        }

        $jobs->markRunning($job);
        $payload = is_array($job->payload) ? $job->payload : [];

        try {
            $identity = RouterIdentity::normalize(
                $payload['identity'] ?? $router->identity,
                $router->name,
            );
            $payload['identity'] = $identity;

            $gateway->setIdentity($router, $identity, $correlationId);
            $probe = $gateway->probe($router, $correlationId);
            $passed = hash_equals($identity, $probe->identity);

            $payload['verification'] = [
                'status' => $passed ? 'passed' : 'failed',
                'expected' => $identity,
                'actual' => $probe->identity,
                'verified_at' => now()->toIso8601String(),
            ];
            $job->forceFill(['payload' => $payload])->save();

            if (! $passed) {
                $router->forceFill(['identity' => $probe->identity])->save();
                $jobs->markFailed(
                    $job,
                    'RouterOS identity was applied, but read-back verification found a mismatch.',
                    'identity_verification',
                );

                return;
            }

            $router->forceFill(['identity' => $probe->identity])->save();
            $jobs->markSucceeded($job);
        } catch (Throwable $exception) {
            $payload['verification'] = [
                'status' => 'failed',
                'expected' => $payload['identity'] ?? null,
                'actual' => null,
                'verified_at' => now()->toIso8601String(),
            ];
            $job->forceFill(['payload' => $payload])->save();

            $jobs->markFailed(
                $job,
                $exception,
                $exception instanceof RouterProbeException ? $exception->category->value : null,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $job = RouterJob::find($this->routerJobId);

        if ($job !== null && ! $job->isSettled()) {
            app(RouterJobService::class)->markFailed(
                $job,
                $exception ?? 'Identity synchronization failed.',
                $exception instanceof RouterProbeException ? $exception->category->value : null,
            );
        }
    }
}
