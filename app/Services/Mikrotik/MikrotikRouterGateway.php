<?php

namespace App\Services\Mikrotik;

use App\Contracts\RouterGateway;
use App\Data\RouterProbeResult;
use App\Enums\RouterErrorCategory;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use Mivo\LaravelMikrotikRos7\Services\SystemManager;
use Mivo\MikrotikRos7\Exceptions\MikrotikException;
use Mivo\MikrotikRos7\Client;
use Throwable;

/**
 * Adapter around the installed Mivo RouterOS 7 REST client.
 *
 * Verified against mivodev/laravel-mikrotik-api-ros7 v0.2.1 and
 * mivodev/mikrotik-api-ros7 v0.1.0:
 *  - SystemManager::getIdentity() calls GET /rest/system/identity
 *  - SystemManager::getResources() calls GET /rest/system/resource
 *  - The underlying Client exposes get/put/patch/post/delete.
 *
 * Only the read paths used in Phase 1 are exposed here.
 */
class MikrotikRouterGateway implements RouterGateway
{
    public function __construct(
        private readonly RouterConnectionFactory $connections,
        private readonly RouterApiRecorder $recorder,
    ) {}

    public function probe(Router $router, string $correlationId): RouterProbeResult
    {
        $client = $this->connections->make($router);
        $overallStarted = hrtime(true);

        $identity = $this->readIdentity($client, $router, $correlationId);

        if (blank($identity) || strcasecmp($identity, 'Unknown') === 0) {
            throw new RouterProbeException(
                'RouterOS returned an empty identity response.',
                RouterErrorCategory::InvalidResponse
            );
        }

        $resources = $this->readResources($client, $router, $correlationId);
        $latencyMs = (int) round((hrtime(true) - $overallStarted) / 1_000_000);

        return new RouterProbeResult(
            identity: $identity,
            version: $this->stringOrNull($resources['version'] ?? null),
            boardName: $this->stringOrNull($resources['board-name'] ?? null),
            uptimeSeconds: $this->parseUptime($this->stringOrNull($resources['uptime'] ?? null)),
            cpuLoad: $this->intOrNull($resources['cpu-load'] ?? null),
            freeMemory: $this->intOrNull($resources['free-memory'] ?? null),
            totalMemory: $this->intOrNull($resources['total-memory'] ?? null),
            latencyMs: $latencyMs,
            resources: $resources,
        );
    }

    public function hotspotProfiles(Router $router, string $correlationId): array
    {
        $client = $this->connections->make($router);
        $started = hrtime(true);

        try {
            $profiles = $client->get('/rest/ip/hotspot/profile');
            $latency = $this->elapsedMs($started);

            $this->recorder->success(
                $router,
                'GET',
                '/rest/ip/hotspot/profile',
                $latency,
                200,
                [],
                ['count' => count($profiles)],
                $correlationId
            );

            return $profiles;
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'GET', '/rest/ip/hotspot/profile', $started, $exception, $correlationId);

            throw $exception instanceof RouterProbeException
                ? $exception
                : RouterProbeException::fromThrowable($exception);
        }
    }

    private function readIdentity(Client $client, Router $router, string $correlationId): string
    {
        $started = hrtime(true);

        try {
            $identity = (new SystemManager($client))->getIdentity();
            $this->recorder->success(
                $router,
                'GET',
                '/rest/system/identity',
                $this->elapsedMs($started),
                200,
                [],
                ['identity_present' => filled($identity)],
                $correlationId
            );

            return $identity;
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'GET', '/rest/system/identity', $started, $exception, $correlationId);

            throw $exception instanceof RouterProbeException
                ? $exception
                : RouterProbeException::fromThrowable($exception);
        }
    }

    /** @return array<string, mixed> */
    private function readResources(Client $client, Router $router, string $correlationId): array
    {
        $started = hrtime(true);

        try {
            $rows = (new SystemManager($client))->getResources();
            $latency = $this->elapsedMs($started);
            $resources = is_array($rows[0] ?? null) ? $rows[0] : [];

            $this->recorder->success(
                $router,
                'GET',
                '/rest/system/resource',
                $latency,
                200,
                [],
                ['fields' => array_keys($resources)],
                $correlationId
            );

            return $resources;
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'GET', '/rest/system/resource', $started, $exception, $correlationId);

            throw $exception instanceof RouterProbeException
                ? $exception
                : RouterProbeException::fromThrowable($exception);
        }
    }

    private function recordFailure(
        Router $router,
        string $method,
        string $endpoint,
        int $started,
        Throwable $exception,
        string $correlationId,
    ): void {
        $probeException = $exception instanceof RouterProbeException
            ? $exception
            : RouterProbeException::fromThrowable($exception);

        $httpStatus = null;

        if ($exception instanceof MikrotikException) {
            $code = $exception->getCode();

            if (is_int($code) && $code >= 400 && $code <= 599) {
                $httpStatus = $code;
            }
        }

        $this->recorder->failure(
            $router,
            $method,
            $endpoint,
            $this->elapsedMs($started),
            $probeException->category,
            $probeException->getMessage(),
            $httpStatus,
            [],
            [],
            $correlationId
        );
    }

    private function elapsedMs(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * RouterOS durations look like "1w2d03:04:05" or "03:04:05".
     */
    private function parseUptime(?string $uptime): ?int
    {
        if ($uptime === null) {
            return null;
        }

        $seconds = 0;
        $matched = preg_match_all('/(\d+)(w|d|h|m|s)/', $uptime, $matches, PREG_SET_ORDER);

        if ($matched === false || $matched === 0) {
            return null;
        }

        foreach ($matches as $match) {
            $seconds += (int) $match[1] * match ($match[2]) {
                'w' => 604800,
                'd' => 86400,
                'h' => 3600,
                'm' => 60,
                's' => 1,
            };
        }

        return $seconds;
    }
}
