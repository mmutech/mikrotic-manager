<?php

namespace App\Services;

use App\Enums\RouterErrorCategory;
use App\Models\ApiLog;
use App\Models\Router;
use App\Support\CorrelationContext;
use App\Support\Redactor;
use Illuminate\Support\Facades\Log;
use Throwable;

class RouterApiRecorder
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly CorrelationContext $correlation,
    ) {}

    /**
     * @param  array<string, mixed>  $requestMeta
     * @param  array<string, mixed>  $responseMeta
     */
    public function success(
        Router $router,
        string $method,
        string $endpoint,
        int $latencyMs,
        ?int $httpStatus = 200,
        array $requestMeta = [],
        array $responseMeta = [],
        ?string $correlationId = null,
    ): void {
        $this->write($router, $method, $endpoint, $latencyMs, true, $httpStatus, null, null, $requestMeta, $responseMeta, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $requestMeta
     * @param  array<string, mixed>  $responseMeta
     */
    public function failure(
        Router $router,
        string $method,
        string $endpoint,
        int $latencyMs,
        RouterErrorCategory $category,
        ?string $errorMessage,
        ?int $httpStatus = null,
        array $requestMeta = [],
        array $responseMeta = [],
        ?string $correlationId = null,
    ): void {
        $this->write($router, $method, $endpoint, $latencyMs, false, $httpStatus, $category, $errorMessage, $requestMeta, $responseMeta, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $requestMeta
     * @param  array<string, mixed>  $responseMeta
     */
    private function write(
        Router $router,
        string $method,
        string $endpoint,
        int $latencyMs,
        bool $success,
        ?int $httpStatus,
        ?RouterErrorCategory $category,
        ?string $errorMessage,
        array $requestMeta,
        array $responseMeta,
        ?string $correlationId,
    ): void {
        try {
            ApiLog::create([
                'router_id' => $router->getKey(),
                'correlation_id' => $correlationId ?? $this->correlation->id(),
                'method' => mb_substr(strtoupper($method), 0, 8),
                'endpoint' => mb_substr($endpoint, 0, 255),
                'http_status' => $httpStatus,
                'latency_ms' => max(0, $latencyMs),
                'success' => $success,
                'error_category' => $category?->value,
                'error_message' => $errorMessage === null
                    ? null
                    : $this->redactor->scrubString($errorMessage),
                'request_meta' => $requestMeta === [] ? null : $this->redactor->redact($requestMeta),
                'response_meta' => $responseMeta === [] ? null : $this->redactor->redact($responseMeta),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Audit/telemetry must never block or break a router operation.
            Log::error('Unable to persist router API log.', [
                'router_id' => $router->getKey(),
                'error' => $this->redactor->scrubString($exception->getMessage()),
            ]);
        }
    }
}
