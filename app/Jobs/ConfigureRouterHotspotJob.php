<?php

namespace App\Jobs;

use App\Contracts\RouterGateway;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Models\RouterJob;
use App\Services\RouterJobService;
use App\Support\CorrelationContext;
use App\Support\HotspotServerConfiguration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ConfigureRouterHotspotJob implements ShouldQueue
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
            ->where('type', 'configure_hotspot')
            ->first();

        if ($job === null || ! $job->status->isPending()) {
            return;
        }

        $router = Router::find($this->routerId);

        if ($router === null) {
            $jobs->markFailed($job, 'Router is not available for HotSpot configuration.');

            return;
        }

        $jobs->markRunning($job);

        $payload = is_array($job->payload) ? $job->payload : [];

        try {
            $payload['server_name'] = HotspotServerConfiguration::nameFromRouter($router);
            $payload['address_pool'] = HotspotServerConfiguration::addressPoolFromRouter($router);
            $configuration = HotspotServerConfiguration::normalize($payload);
        } catch (RouterProbeException $exception) {
            $jobs->markFailed($job, $exception);

            return;
        }

        $checks = $gateway->configureHotspotServer($router, $configuration, $correlationId);
        $payload['configuration'] = $configuration;
        $payload['verification'] = $checks;
        $payload['verification_at'] = now()->toIso8601String();
        $job->forceFill(['payload' => $payload])->save();

        foreach ($checks as $check) {
            if ($check['status'] === 'failed') {
                $jobs->markFailed($job, 'RouterOS HotSpot configuration was applied, but read-back verification found mismatches.', 'configuration_verification');

                return;
            }
        }

        $jobs->markSucceeded($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = RouterJob::find($this->routerJobId);

        if ($job !== null && ! $job->isSettled()) {
            app(RouterJobService::class)->markFailed(
                $job,
                $exception ?? 'Router HotSpot configuration failed.',
                $exception instanceof RouterProbeException ? $exception->category->value : null,
            );
        }
    }
}
