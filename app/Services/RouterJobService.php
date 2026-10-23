<?php

namespace App\Services;

use App\Enums\RouterJobStatus;
use App\Enums\RouterStatus;
use App\Models\Router;
use App\Models\RouterJob;
use App\Models\User;
use App\Support\Redactor;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class RouterJobService
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly \App\Support\CorrelationContext $correlation,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        Router $router,
        string $type,
        ?User $actor = null,
        array $payload = [],
        ?Model $related = null,
        ?string $idempotencyKey = null,
    ): RouterJob {
        if ($idempotencyKey !== null) {
            $existing = RouterJob::query()
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('status', [
                    RouterJobStatus::Queued->value,
                    RouterJobStatus::Running->value,
                    RouterJobStatus::Retrying->value,
                ])
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return RouterJob::create([
            'router_id' => $router->getKey(),
            'type' => $type,
            'status' => RouterJobStatus::Queued,
            'idempotency_key' => $idempotencyKey,
            'payload' => $this->redactor->redact($payload),
            'max_attempts' => 3,
            'available_at' => now(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'correlation_id' => $this->correlation->id(),
            'created_by' => $actor?->getKey(),
        ]);
    }

    public function markRunning(RouterJob $job): void
    {
        $job->forceFill([
            'status' => RouterJobStatus::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => $job->started_at ?? now(),
            'finished_at' => null,
            'error_category' => null,
            'error_message' => null,
        ])->save();
    }

    public function markSucceeded(RouterJob $job): void
    {
        $job->forceFill([
            'status' => RouterJobStatus::Succeeded,
            'finished_at' => now(),
            'error_category' => null,
            'error_message' => null,
        ])->save();
    }

    public function markFailed(RouterJob $job, Throwable|string $error, ?string $category = null): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $job->forceFill([
            'status' => RouterJobStatus::Failed,
            'finished_at' => now(),
            'error_category' => $category,
            'error_message' => $this->redactor->scrubString($message),
        ])->save();
    }

    public function cancel(RouterJob $job): void
    {
        $job->forceFill([
            'status' => RouterJobStatus::Cancelled,
            'finished_at' => now(),
        ])->save();
    }

    public function pendingTestExists(Router $router): bool
    {
        return RouterJob::query()
            ->where('router_id', $router->getKey())
            ->where('type', 'test_connection')
            ->whereIn('status', [
                RouterJobStatus::Queued->value,
                RouterJobStatus::Running->value,
                RouterJobStatus::Retrying->value,
            ])
            ->exists();
    }

    public function markRouterDisabled(Router $router): void
    {
        $router->forceFill(['status' => RouterStatus::Disabled, 'monitoring_enabled' => false])->save();
    }
}
