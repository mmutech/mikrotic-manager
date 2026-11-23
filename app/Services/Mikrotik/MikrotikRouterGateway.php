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
use Mivo\MikrotikRos7\Client;
use Mivo\MikrotikRos7\Exceptions\MikrotikException;
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
 * Health probes use read paths; identity and bridge changes run only through
 * explicit, tracked jobs.
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

    public function setIdentity(Router $router, string $identity, string $correlationId): void
    {
        $client = $this->connections->make($router);
        $started = hrtime(true);

        try {
            $client->put('/rest/system/identity', ['name' => $identity]);

            $this->recorder->success(
                $router,
                'PUT',
                '/rest/system/identity',
                $this->elapsedMs($started),
                200,
                ['identity_present' => filled($identity)],
                [],
                $correlationId
            );
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'PUT', '/rest/system/identity', $started, $exception, $correlationId);

            throw $exception instanceof RouterProbeException
                ? $exception
                : RouterProbeException::fromThrowable($exception);
        }
    }

    public function ethernetInterfaces(Router $router, string $correlationId): array
    {
        $client = $this->connections->make($router);
        $interfaces = $this->getRows($client, $router, '/rest/interface', $correlationId);
        $bridgePorts = $this->getRows($client, $router, '/rest/interface/bridge/port', $correlationId);
        $bridgeByInterface = [];

        foreach ($bridgePorts as $bridgePort) {
            $interface = $this->stringOrNull($bridgePort['interface'] ?? null);
            $bridge = $this->stringOrNull($bridgePort['bridge'] ?? null);

            if ($interface !== null && $bridge !== null) {
                $bridgeByInterface[$interface] = $bridge;
            }
        }

        $ethernetInterfaces = [];

        foreach ($interfaces as $interface) {
            $name = $this->stringOrNull($interface['name'] ?? null);
            $type = strtolower((string) ($interface['type'] ?? ''));

            if ($name === null || ! in_array($type, ['ether', 'ethernet'], true)) {
                continue;
            }

            $ethernetInterfaces[] = [
                'name' => $name,
                'running' => $this->routerOsBoolean($interface['running'] ?? false),
                'disabled' => $this->routerOsBoolean($interface['disabled'] ?? false),
                'bridge' => $bridgeByInterface[$name] ?? null,
            ];
        }

        usort($ethernetInterfaces, fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        return $ethernetInterfaces;
    }

    public function privateIpv4Addresses(Router $router, string $correlationId): array
    {
        $client = $this->connections->make($router);
        $rows = $this->getRows($client, $router, '/rest/ip/address', $correlationId);
        $privateIpv4Addresses = [];

        foreach ($rows as $row) {
            if ($this->routerOsBoolean($row['disabled'] ?? false)
                || $this->routerOsBoolean($row['invalid'] ?? false)) {
                continue;
            }

            $address = $this->stringOrNull($row['address'] ?? null);

            if ($address === null) {
                continue;
            }

            $ip = explode('/', $address, 2)[0];

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || ! $this->isPrivateIpv4($ip)) {
                continue;
            }

            $privateIpv4Addresses[$ip] = [
                'ip' => $ip,
                'interface' => $this->stringOrNull($row['interface'] ?? null),
            ];
        }

        return array_values($privateIpv4Addresses);
    }

    public function configureBridge(
        Router $router,
        string $bridgeName,
        array $interfaces,
        string $correlationId,
    ): void {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $bridgeName) !== 1) {
            throw RouterProbeException::configuration('The bridge name must use 1 to 32 letters, numbers, dots, underscores, or hyphens.');
        }

        foreach ($interfaces as $interface) {
            if (! is_string($interface) || $interface === '') {
                throw RouterProbeException::configuration('Every selected interface must have a valid name.');
            }
        }

        if ($interfaces === [] || count(array_unique($interfaces)) !== count($interfaces)) {
            throw RouterProbeException::configuration('Select one or more distinct Ethernet interfaces.');
        }

        $client = $this->connections->make($router);
        $routerInterfaces = $this->getRows($client, $router, '/rest/interface', $correlationId);
        $interfaceTypes = [];
        $bridgeExists = false;

        foreach ($routerInterfaces as $routerInterface) {
            $name = $this->stringOrNull($routerInterface['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $type = strtolower((string) ($routerInterface['type'] ?? ''));
            $interfaceTypes[$name] = $type;

            if ($name === $bridgeName) {
                if ($type !== 'bridge') {
                    throw RouterProbeException::configuration(
                        'An interface with the requested bridge name already exists and is not a bridge.'
                    );
                }

                $bridgeExists = true;
            }
        }

        $bridgePorts = $this->getRows($client, $router, '/rest/interface/bridge/port', $correlationId);
        $bridgeByInterface = [];

        foreach ($bridgePorts as $bridgePort) {
            $interface = $this->stringOrNull($bridgePort['interface'] ?? null);
            $bridge = $this->stringOrNull($bridgePort['bridge'] ?? null);

            if ($interface !== null && $bridge !== null) {
                $bridgeByInterface[$interface] = $bridge;
            }
        }

        foreach ($interfaces as $interface) {
            if (! in_array($interfaceTypes[$interface] ?? null, ['ether', 'ethernet'], true)) {
                throw RouterProbeException::configuration(
                    'A selected interface is no longer an available Ethernet interface.'
                );
            }

            $currentBridge = $bridgeByInterface[$interface] ?? null;

            if ($currentBridge !== null && $currentBridge !== $bridgeName) {
                throw RouterProbeException::configuration(
                    'A selected interface already belongs to a different bridge. Remove it from that bridge before continuing.'
                );
            }
        }

        if (! $bridgeExists) {
            $this->put(
                $client,
                $router,
                '/rest/interface/bridge',
                ['name' => $bridgeName],
                $correlationId,
            );
        }

        foreach ($interfaces as $interface) {
            if (($bridgeByInterface[$interface] ?? null) === $bridgeName) {
                continue;
            }

            $this->put(
                $client,
                $router,
                '/rest/interface/bridge/port',
                ['bridge' => $bridgeName, 'interface' => $interface],
                $correlationId,
            );
        }
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

    public function hotspotUserProfiles(Router $router, string $correlationId): array
    {
        $profiles = $this->getRows(
            $this->connections->make($router),
            $router,
            '/rest/ip/hotspot/user/profile',
            $correlationId,
        );
        $hotspotUserProfiles = [];

        foreach ($profiles as $profile) {
            $name = $this->stringOrNull($profile['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $hotspotUserProfiles[] = [
                'name' => $name,
                'rate_limit' => $this->stringOrNull($profile['rate-limit'] ?? null),
                'shared_users' => $this->stringOrNull($profile['shared-users'] ?? null) ?? '1',
            ];
        }

        usort(
            $hotspotUserProfiles,
            fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']),
        );

        return $hotspotUserProfiles;
    }

    public function createHotspotUserProfile(
        Router $router,
        string $profileName,
        ?string $rateLimit,
        int $sharedUsers,
        string $correlationId,
    ): void {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $profileName) !== 1) {
            throw RouterProbeException::configuration('The Hotspot profile name must use 1 to 64 letters, numbers, dots, underscores, or hyphens.');
        }

        if ($sharedUsers < 1) {
            throw RouterProbeException::configuration('The Hotspot profile shared-user limit must be at least one.');
        }

        if ($rateLimit !== null && preg_match('/\A[1-9]\d*k\/[1-9]\d*k\z/', $rateLimit) !== 1) {
            throw RouterProbeException::configuration('The Hotspot profile rate limit must use positive upload/download rates in kbps.');
        }

        $client = $this->connections->make($router);
        $endpoint = '/rest/ip/hotspot/user/profile';
        $profiles = $this->getRows($client, $router, $endpoint, $correlationId);
        $existing = $this->findHotspotUserProfile($profiles, $profileName);

        if ($existing !== null) {
            $existingRateLimit = $this->stringOrNull($existing['rate-limit'] ?? null);
            $existingSharedUsers = $this->stringOrNull($existing['shared-users'] ?? null) ?? '1';

            if ($existingRateLimit !== $rateLimit || $existingSharedUsers !== (string) $sharedUsers) {
                throw RouterProbeException::configuration(
                    "A Hotspot user profile named {$profileName} already exists with different settings. It was not changed."
                );
            }

            return;
        }

        $payload = [
            'name' => $profileName,
            'shared-users' => (string) $sharedUsers,
        ];

        if ($rateLimit !== null) {
            $payload['rate-limit'] = $rateLimit;
        }

        $this->put($client, $router, $endpoint, $payload, $correlationId);
        $profiles = $this->getRows($client, $router, $endpoint, $correlationId);
        $createdProfile = $this->findHotspotUserProfile($profiles, $profileName);

        if (
            $createdProfile === null
            || $this->stringOrNull($createdProfile['rate-limit'] ?? null) !== $rateLimit
            || ($this->stringOrNull($createdProfile['shared-users'] ?? null) ?? '1') !== (string) $sharedUsers
        ) {
            throw RouterProbeException::configuration(
                "RouterOS did not confirm the requested Hotspot user profile settings for {$profileName}."
            );
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

    /** @return array<int, array<string, mixed>> */
    private function getRows(Client $client, Router $router, string $endpoint, string $correlationId): array
    {
        $started = hrtime(true);

        try {
            $rows = $client->get($endpoint);

            $this->recorder->success(
                $router,
                'GET',
                $endpoint,
                $this->elapsedMs($started),
                200,
                [],
                ['count' => count($rows)],
                $correlationId
            );

            return $rows;
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'GET', $endpoint, $started, $exception, $correlationId);

            throw $exception instanceof RouterProbeException
                ? $exception
                : RouterProbeException::fromThrowable($exception);
        }
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function put(
        Client $client,
        Router $router,
        string $endpoint,
        array $payload,
        string $correlationId,
    ): void {
        $started = hrtime(true);

        try {
            $client->put($endpoint, $payload);

            $this->recorder->success(
                $router,
                'PUT',
                $endpoint,
                $this->elapsedMs($started),
                200,
                ['fields' => array_keys($payload)],
                [],
                $correlationId
            );
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'PUT', $endpoint, $started, $exception, $correlationId);

            throw $exception instanceof RouterProbeException
                ? $exception
                : RouterProbeException::fromThrowable($exception);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<string, mixed>|null
     */
    private function findHotspotUserProfile(array $profiles, string $profileName): ?array
    {
        foreach ($profiles as $profile) {
            if (($profile['name'] ?? null) === $profileName) {
                return $profile;
            }
        }

        return null;
    }

    private function routerOsBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function isPrivateIpv4(string $ip): bool
    {
        $octets = explode('.', $ip);

        if (count($octets) !== 4) {
            return false;
        }

        $firstOctet = (int) $octets[0];
        $secondOctet = (int) $octets[1];

        return $firstOctet === 10
            || ($firstOctet === 172 && $secondOctet >= 16 && $secondOctet <= 31)
            || ($firstOctet === 192 && $secondOctet === 168);
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
