<?php

namespace App\Jobs;

use App\Contracts\RouterGateway;
use App\Models\Router;
use App\Models\RouterJob;
use App\Services\RouterJobService;
use App\Support\CorrelationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ConfigureRouterBridgeJob implements ShouldQueue
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
            ->where('type', 'configure_bridge')
            ->first();

        if ($job === null || ! $job->status->isPending()) {
            return;
        }

        $router = Router::find($this->routerId);

        if ($router === null) {
            $jobs->markFailed($job, 'Router is not available for bridge configuration.');

            return;
        }

        $configuration = $job->payload ?? [];
        $bridgeName = $configuration['bridge_name'] ?? null;
        $interfaces = $configuration['interfaces'] ?? null;
        $interfacesToRemove = $configuration['remove_interfaces'] ?? [];

        if (
            ! is_string($bridgeName)
            || ! is_array($interfaces)
            || ! is_array($interfacesToRemove)
            || ($interfaces === [] && $interfacesToRemove === [])
            || ($configuration['management_impact_confirmed'] ?? false) !== true
        ) {
            $jobs->markFailed($job, 'Bridge configuration is invalid or missing the required management-impact confirmation.');

            return;
        }

        foreach ([$interfaces, $interfacesToRemove] as $selection) {
            foreach ($selection as $interface) {
                if (! is_string($interface) || $interface === '') {
                    $jobs->markFailed($job, 'Bridge configuration contains an invalid interface name.');

                    return;
                }
            }
        }

        if (
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $bridgeName) !== 1
            || count(array_unique($interfaces)) !== count($interfaces)
            || count(array_unique($interfacesToRemove)) !== count($interfacesToRemove)
            || array_intersect($interfaces, $interfacesToRemove) !== []
        ) {
            $jobs->markFailed($job, 'Bridge configuration contains an invalid bridge name or duplicate interfaces.');

            return;
        }

        $jobs->markRunning($job);
        $gateway->configureBridge(
            $router,
            $bridgeName,
            array_values($interfaces),
            $correlationId,
            array_values($interfacesToRemove),
        );
        $jobs->markSucceeded($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = RouterJob::find($this->routerJobId);

        if ($job !== null && ! $job->isSettled()) {
            app(RouterJobService::class)->markFailed($job, $exception ?? 'Bridge configuration failed.');
        }
    }
}
