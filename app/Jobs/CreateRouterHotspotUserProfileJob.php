<?php

namespace App\Jobs;

use App\Contracts\RouterGateway;
use App\Models\Router;
use App\Models\RouterJob;
use App\Services\RouterJobService;
use App\Support\CorrelationContext;
use App\Support\HotspotRateLimit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use InvalidArgumentException;
use Throwable;

class CreateRouterHotspotUserProfileJob implements ShouldQueue
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
            ->where('type', 'create_hotspot_user_profile')
            ->first();

        if ($job === null || ! $job->status->isPending()) {
            return;
        }

        $router = Router::find($this->routerId);

        if ($router === null) {
            $jobs->markFailed($job, 'Router is not available for Hotspot profile creation.');

            return;
        }

        $configuration = $job->payload ?? [];
        $profileName = $configuration['profile_name'] ?? null;
        $rateLimit = $configuration['rate_limit'] ?? null;
        $rateLimit = $rateLimit === '' ? null : $rateLimit;

        if ($rateLimit !== null && ! is_string($rateLimit)) {
            $jobs->markFailed($job, 'Hotspot user profile configuration is invalid or incomplete.');

            return;
        }

        try {
            $rateLimit = HotspotRateLimit::canonical($rateLimit);
        } catch (InvalidArgumentException) {
            $jobs->markFailed($job, 'Hotspot user profile configuration is invalid or incomplete.');

            return;
        }

        $sharedUsers = $configuration['shared_users'] ?? null;
        $sharedUsers = is_int($sharedUsers) || (is_string($sharedUsers) && ctype_digit($sharedUsers))
            ? (int) $sharedUsers
            : null;

        if (
            ! is_string($profileName)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $profileName) !== 1
            || $sharedUsers === null
            || $sharedUsers < 1
        ) {
            $jobs->markFailed($job, 'Hotspot user profile configuration is invalid or incomplete.');

            return;
        }

        $jobs->markRunning($job);
        $gateway->createHotspotUserProfile($router, $profileName, $rateLimit, $sharedUsers, $correlationId);
        $jobs->markSucceeded($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = RouterJob::find($this->routerJobId);

        if ($job !== null && ! $job->isSettled()) {
            app(RouterJobService::class)->markFailed(
                $job,
                $exception ?? 'Hotspot user profile creation failed.',
            );
        }
    }
}
