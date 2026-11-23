<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\RouterJob;
use App\Services\Mikrotik\MikrotikRouterConfigurator;
use App\Services\RouterJobService;
use App\Support\CorrelationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class VerifyRouterNetworkJob implements ShouldQueue
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

    public function handle(MikrotikRouterConfigurator $configurator, RouterJobService $jobs): void
    {
        $correlationId = app(CorrelationContext::class)->set($this->correlationId);
        $job = RouterJob::query()
            ->whereKey($this->routerJobId)
            ->where('router_id', $this->routerId)
            ->where('type', 'verify_network')
            ->first();

        if ($job === null || ! $job->status->isPending()) {
            return;
        }

        $router = Router::find($this->routerId);

        if ($router === null) {
            $jobs->markFailed($job, 'Router is not available for network verification.');

            return;
        }

        $configuration = $job->payload['configuration'] ?? null;

        if (! is_array($configuration)) {
            $jobs->markFailed($job, 'No saved network configuration is available to verify.');

            return;
        }

        $jobs->markRunning($job);
        $configuration = $configurator->normalize($configuration);
        $checks = $configurator->verify($router, $configuration, $correlationId);
        $payload = $job->payload ?? [];
        $payload['configuration'] = $configuration;
        $payload['verification'] = $checks;
        $payload['verification_at'] = now()->toIso8601String();
        $job->forceFill(['payload' => $payload])->save();

        if ($this->hasFailedChecks($checks)) {
            $jobs->markFailed($job, 'Read-back verification found network configuration mismatches.', 'configuration_verification');

            return;
        }

        $jobs->markSucceeded($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = RouterJob::find($this->routerJobId);

        if ($job !== null && ! $job->isSettled()) {
            app(RouterJobService::class)->markFailed($job, $exception ?? 'Router network verification failed.');
        }
    }

    /**
     * @param  array<string, array{status: string, message: string}>  $checks
     */
    private function hasFailedChecks(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'failed') {
                return true;
            }
        }

        return false;
    }
}
