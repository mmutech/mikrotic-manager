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

class UpdateRouterHotspotUserProfileJob implements ShouldQueue
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
            ->where('type', 'update_hotspot_user_profile')
            ->first();

        if ($job === null || ! $job->status->isPending()) {
            return;
        }

        $router = Router::find($this->routerId);

        if ($router === null) {
            $jobs->markFailed($job, 'Router is not available for Hotspot profile modification.');

            return;
        }

        $configuration = $job->payload ?? [];
        $profileId = $configuration['profile_id'] ?? null;
        $profileName = $configuration['profile_name'] ?? null;
        $expectedSharedUsers = $this->positiveInt($configuration['expected_shared_users'] ?? null);
        $sharedUsers = $this->positiveInt($configuration['shared_users'] ?? null);

        if (
            ! is_string($profileId)
            || preg_match('/\A\*[0-9A-Fa-f]+\z/', $profileId) !== 1
            || ! is_string($profileName)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $profileName) !== 1
            || $expectedSharedUsers === null
            || $sharedUsers === null
        ) {
            $jobs->markFailed($job, 'Hotspot user profile update configuration is invalid or incomplete.');

            return;
        }

        try {
            $expectedRateLimit = $this->canonicalRateLimit($configuration['expected_rate_limit'] ?? null);
            $rateLimit = $this->canonicalRateLimit($configuration['rate_limit'] ?? null);
        } catch (InvalidArgumentException) {
            $jobs->markFailed($job, 'Hotspot user profile update configuration is invalid or incomplete.');

            return;
        }

        $jobs->markRunning($job);
        $gateway->updateHotspotUserProfile(
            $router,
            $profileId,
            $profileName,
            $expectedRateLimit,
            $expectedSharedUsers,
            $rateLimit,
            $sharedUsers,
            $correlationId,
        );
        $jobs->markSucceeded($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = RouterJob::find($this->routerJobId);

        if ($job !== null && ! $job->isSettled()) {
            app(RouterJobService::class)->markFailed(
                $job,
                $exception ?? 'Hotspot user profile update failed.',
            );
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! (is_int($value) || (is_string($value) && ctype_digit($value)))) {
            return null;
        }

        $integer = (int) $value;

        return $integer >= 1 ? $integer : null;
    }

    private function canonicalRateLimit(mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Invalid rate limit.');
        }

        return HotspotRateLimit::canonical($value);
    }
}
