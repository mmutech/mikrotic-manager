<?php

namespace App\Services;

use App\Enums\HealthCheckStatus;
use App\Enums\RouterErrorCategory;
use App\Enums\RouterStatus;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Models\RouterHealthCheck;
use App\Models\User;
use App\Support\CorrelationContext;
use App\Support\Redactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RouterHealthService
{
    public function __construct(
        private readonly \App\Contracts\RouterGateway $gateway,
        private readonly AuditLogger $audit,
        private readonly Redactor $redactor,
        private readonly CorrelationContext $correlation,
    ) {}

    /**
     * Perform a read-only health probe and persist the outcome. Connectivity
     * failures are represented in the result rather than thrown, so queued
     * health checks do not endlessly retry a router that is simply offline.
     */
    public function check(
        Router $router,
        ?User $actor = null,
        ?string $correlationId = null,
        bool $audit = false,
    ): RouterHealthCheck {
        $correlationId = $correlationId ?? $this->correlation->id();

        try {
            $probe = $this->gateway->probe($router, $correlationId);

            $check = DB::transaction(function () use ($router, $probe): RouterHealthCheck {
                $attributes = [
                    'last_checked_at' => now(),
                    'last_seen_at' => now(),
                    'latency_ms' => $probe->latencyMs,
                    'identity' => $probe->identity,
                    'ros_version' => $probe->version,
                    'board_name' => $probe->boardName,
                    'consecutive_failures' => 0,
                    'last_error_category' => null,
                    'last_error_message' => null,
                ];

                $status = $router->status;

                if (! in_array($status, [RouterStatus::Disabled, RouterStatus::Archived], true)) {
                    $attributes['status'] = RouterStatus::Online;
                }

                $attributes['metadata'] = array_merge($router->metadata ?? [], [
                    'uptime_seconds' => $probe->uptimeSeconds,
                    'cpu_load' => $probe->cpuLoad,
                    'free_memory' => $probe->freeMemory,
                    'total_memory' => $probe->totalMemory,
                    'last_probe_at' => now()->toIso8601String(),
                ]);

                $router->forceFill($attributes)->save();

                return RouterHealthCheck::create([
                    'router_id' => $router->getKey(),
                    'status' => HealthCheckStatus::Online,
                    'latency_ms' => $probe->latencyMs,
                    'identity' => $probe->identity,
                    'ros_version' => $probe->version,
                    'error_category' => null,
                    'details' => [
                        'cpu_load' => $probe->cpuLoad,
                        'free_memory' => $probe->freeMemory,
                        'total_memory' => $probe->totalMemory,
                    ],
                    'checked_at' => now(),
                ]);
            });

            if ($audit) {
                $this->audit->record(
                    'router.health_check_succeeded',
                    $router,
                    after: ['status' => RouterStatus::Online->value, 'latency_ms' => $probe->latencyMs],
                    metadata: [
                        'identity' => $probe->identity,
                        'ros_version' => $probe->version,
                        'correlation_id' => $correlationId,
                    ],
                    actor: $actor,
                );
            }

            return $check;
        } catch (RouterProbeException $exception) {
            return $this->recordFailure($router, $exception, $correlationId, $actor, $audit);
        }
    }

    private function recordFailure(
        Router $router,
        RouterProbeException $exception,
        string $correlationId,
        ?User $actor,
        bool $audit,
    ): RouterHealthCheck {
        $safeMessage = $this->redactor->scrubString($exception->getMessage());
        $failures = $router->consecutive_failures + 1;

        $check = DB::transaction(function () use ($router, $exception, $safeMessage, $failures): RouterHealthCheck {
            $status = $router->status;

            if (! in_array($status, [RouterStatus::Disabled, RouterStatus::Archived], true)) {
                $status = in_array($exception->category, [
                    RouterErrorCategory::Authentication,
                    RouterErrorCategory::Authorization,
                    RouterErrorCategory::Tls,
                    RouterErrorCategory::Configuration,
                ], true) ? RouterStatus::Error : RouterStatus::Offline;
            }

            $router->forceFill([
                'status' => $status,
                'last_checked_at' => now(),
                'consecutive_failures' => $failures,
                'last_error_category' => $exception->category,
                'last_error_message' => $safeMessage,
            ])->save();

            return RouterHealthCheck::create([
                'router_id' => $router->getKey(),
                'status' => HealthCheckStatus::fromErrorCategory($exception->category),
                'latency_ms' => null,
                'identity' => null,
                'ros_version' => null,
                'error_category' => $exception->category->value,
                'details' => [
                    'message' => $safeMessage,
                    'consecutive_failures' => $failures,
                ],
                'checked_at' => now(),
            ]);
        });

        Log::info('Router health check failed.', [
            'router_id' => $router->getKey(),
            'category' => $exception->category->value,
            'consecutive_failures' => $failures,
            'correlation_id' => $correlationId,
        ]);

        if ($audit) {
            $this->audit->record(
                'router.health_check_failed',
                $router,
                after: [
                    'status' => $router->status->value,
                    'error_category' => $exception->category->value,
                    'consecutive_failures' => $failures,
                ],
                metadata: ['correlation_id' => $correlationId],
                actor: $actor,
            );
        }

        return $check;
    }
}
