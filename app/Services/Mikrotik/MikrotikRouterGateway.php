<?php

namespace App\Services\Mikrotik;

use App\Contracts\RouterGateway;
use App\Data\RouterProbeResult;
use App\Enums\RouterErrorCategory;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use App\Support\HotspotRateLimit;
use App\Support\HotspotServerConfiguration;
use App\Support\Ipv4Cidr;
use App\Support\RouterIdentity;
use InvalidArgumentException;
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
 *  - Identity writes use POST /rest/system/identity/set because /system/identity
 *    is a recordless menu and does not accept PATCH
 *  - SystemManager::getResources() calls GET /rest/system/resource
 *  - The underlying Client exposes get/put/patch/post/delete.
 *
 * Health probes use read paths; identity and bridge changes run only through
 * explicit, tracked jobs.
 */
class MikrotikRouterGateway implements RouterGateway
{
    private const HOTSPOT_COOKIE_LIFETIME = '3650d';

    private const HOTSPOT_COOKIE_LIFETIME_SECONDS = 315_360_000;

    private const NETWORK_MANAGED_COMMENT_PREFIX = 'MMUTech managed ';

    private const MANAGED_FIREWALL_COMMENT_PREFIX = 'MMUTech managed firewall ';

    private const MANAGED_ADDRESS_COMMENT = 'MMUTech managed LAN address';

    private const MANAGED_POOL_COMMENT = 'MMUTech managed LAN DHCP pool';

    private const MANAGED_DHCP_SERVER_COMMENT = 'MMUTech managed LAN DHCP server';

    private const MANAGED_DHCP_NETWORK_COMMENT = 'MMUTech managed LAN DHCP network';

    private const MANAGED_NAT_COMMENT = 'MMUTech managed LAN masquerade';

    private const MAX_IMPORT_FILE_BYTES = 61439;

    private const FILE_READ_CHUNK_BYTES = 32768;

    private const MAX_EXPORT_FILE_BYTES = 5_242_880;

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
        $endpoint = '/rest/system/identity/set';

        try {
            $client->post($endpoint, ['name' => $identity]);

            $this->recorder->success(
                $router,
                'POST',
                $endpoint,
                $this->elapsedMs($started),
                200,
                ['identity_present' => filled($identity)],
                [],
                $correlationId
            );
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'POST', $endpoint, $started, $exception, $correlationId, $client);

            throw $this->asProbeException($exception, $client);
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

    public function networkConfiguration(Router $router, string $correlationId): array
    {
        $client = $this->connections->make($router);

        return [
            'addresses' => $this->discoverNetworkRows(
                $client,
                $router,
                '/rest/ip/address',
                $correlationId,
                function (array $row): ?array {
                    $address = $this->stringOrNull($row['address'] ?? null);

                    if ($address === null) {
                        return null;
                    }

                    return $this->networkRow($row, $address, $this->networkDetail([
                        $this->stringOrNull($row['interface'] ?? null),
                        $this->stringOrNull($row['network'] ?? null),
                        $this->routerOsBoolean($row['disabled'] ?? false) ? 'disabled' : 'enabled',
                    ]));
                },
            ),
            'pools' => $this->discoverNetworkRows(
                $client,
                $router,
                '/rest/ip/pool',
                $correlationId,
                function (array $row): ?array {
                    $name = $this->stringOrNull($row['name'] ?? null);

                    if ($name === null) {
                        return null;
                    }

                    return $this->networkRow($row, $name, $this->networkDetail([
                        $this->stringOrNull($row['ranges'] ?? null),
                    ]));
                },
            ),
            'dhcp_networks' => $this->discoverNetworkRows(
                $client,
                $router,
                '/rest/ip/dhcp-server/network',
                $correlationId,
                function (array $row): ?array {
                    $address = $this->stringOrNull($row['address'] ?? null);

                    if ($address === null) {
                        return null;
                    }

                    $gateway = $this->stringOrNull($row['gateway'] ?? null);
                    $dnsServer = $this->stringOrNull($row['dns-server'] ?? null);

                    return $this->networkRow($row, $address, $this->networkDetail([
                        $gateway === null ? null : 'gateway '.$gateway,
                        $dnsServer === null ? null : 'DNS '.$dnsServer,
                    ]));
                },
            ),
            'dhcp_servers' => $this->discoverNetworkRows(
                $client,
                $router,
                '/rest/ip/dhcp-server',
                $correlationId,
                function (array $row): ?array {
                    $name = $this->stringOrNull($row['name'] ?? null);

                    if ($name === null) {
                        return null;
                    }

                    $leaseTime = $this->stringOrNull($row['lease-time'] ?? null);

                    return $this->networkRow($row, $name, $this->networkDetail([
                        $this->stringOrNull($row['interface'] ?? null),
                        $this->stringOrNull($row['address-pool'] ?? null),
                        $leaseTime === null ? null : 'lease '.$leaseTime,
                        $this->routerOsBoolean($row['disabled'] ?? false) ? 'disabled' : 'enabled',
                    ]));
                },
            ),
            'nat_rules' => $this->discoverNetworkRows(
                $client,
                $router,
                '/rest/ip/firewall/nat',
                $correlationId,
                function (array $row): ?array {
                    $chain = $this->stringOrNull($row['chain'] ?? null);
                    $action = $this->stringOrNull($row['action'] ?? null);

                    if ($chain === null && $action === null) {
                        return null;
                    }

                    return $this->networkRow($row, ($chain ?? 'unknown chain').' → '.($action ?? 'unknown action'), $this->networkDetail([
                        $this->stringOrNull($row['src-address'] ?? null),
                        $this->stringOrNull($row['out-interface'] ?? null),
                        $this->routerOsBoolean($row['disabled'] ?? false) ? 'disabled' : 'enabled',
                    ]));
                },
            ),
            'firewall_rules' => $this->discoverNetworkRows(
                $client,
                $router,
                '/rest/ip/firewall/filter',
                $correlationId,
                function (array $row): ?array {
                    $chain = $this->stringOrNull($row['chain'] ?? null);
                    $action = $this->stringOrNull($row['action'] ?? null);

                    if ($chain === null && $action === null) {
                        return null;
                    }

                    return $this->networkRow($row, ($chain ?? 'unknown chain').' → '.($action ?? 'unknown action'), $this->networkDetail([
                        $this->stringOrNull($row['src-address'] ?? null),
                        $this->stringOrNull($row['dst-address'] ?? null),
                        $this->stringOrNull($row['in-interface'] ?? null),
                        $this->stringOrNull($row['out-interface'] ?? null),
                        $this->stringOrNull($row['connection-state'] ?? null),
                        $this->routerOsBoolean($row['disabled'] ?? false) ? 'disabled' : 'enabled',
                    ]));
                },
            ),
        ];
    }

    public function managementSourceCidrs(Router $router, string $correlationId): array
    {
        $client = $this->connections->make($router);
        $connections = $this->getRows($client, $router, '/rest/ip/firewall/connection', $correlationId);
        $source = $this->currentManagementSource($client, $router, $connections);

        if ($source === null) {
            return [];
        }

        $candidates = [];

        foreach ($this->getRows($client, $router, '/rest/ip/firewall/filter', $correlationId) as $row) {
            if ($this->routerOsBoolean($row['disabled'] ?? false)) {
                continue;
            }

            $comment = $this->stringOrNull($row['comment'] ?? null);

            if ($comment === null || ! str_starts_with($comment, self::MANAGED_FIREWALL_COMMENT_PREFIX.'input management')) {
                continue;
            }

            $cidr = $this->stringOrNull($row['src-address'] ?? null);

            if ($cidr !== null && Ipv4Cidr::contains($cidr, $source)) {
                $candidates[] = $cidr;
            }
        }

        foreach ($this->getRows($client, $router, '/rest/ip/service', $correlationId) as $row) {
            if ($this->routerOsBoolean($row['disabled'] ?? false)) {
                continue;
            }

            $name = strtolower((string) ($this->stringOrNull($row['name'] ?? null) ?? ''));
            $port = $this->stringOrNull($row['port'] ?? null);

            if (
                ! in_array($name, ['api', 'api-ssl', 'rest', 'www', 'www-ssl'], true)
                && $port !== (string) $router->rest_port
            ) {
                continue;
            }

            foreach (Ipv4Cidr::splitList($this->stringOrNull($row['available-from'] ?? null)) as $cidr) {
                if (Ipv4Cidr::contains($cidr, $source)) {
                    $candidates[] = $cidr;
                }
            }
        }

        return Ipv4Cidr::normalizeList($candidates === [] ? [$source.'/32'] : $candidates);
    }

    /**
     * Find the source address RouterOS sees for the current API connection.
     *
     * @param  array<int, array<string, mixed>>  $connections
     */
    private function currentManagementSource(Client $client, Router $router, array $connections): ?string
    {
        $localPort = $client instanceof DetailedMikrotikClient ? $client->lastLocalPort() : null;
        $localIp = $client instanceof DetailedMikrotikClient ? $client->lastLocalIp() : null;
        $sources = [];

        foreach ($connections as $row) {
            $destinationPort = $row['dst-port'] ?? null;
            $protocol = strtolower((string) ($this->stringOrNull($row['protocol'] ?? null) ?? ''));

            if (
                $protocol !== 'tcp'
                || ! is_scalar($destinationPort)
                || (string) $destinationPort !== (string) $router->rest_port
                || $this->routerOsBoolean($row['invalid'] ?? false)
            ) {
                continue;
            }

            $source = $this->stringOrNull($row['src-address'] ?? null);

            if ($source === null || filter_var($source, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            $sourcePort = $row['src-port'] ?? null;
            $matchesPort = $localPort !== null && is_scalar($sourcePort) && (int) $sourcePort === $localPort;
            $matchesLocalIp = $localIp !== null && $source === $localIp;

            if (($localPort !== null || $localIp !== null) && ! $matchesPort && ! $matchesLocalIp) {
                continue;
            }

            $sources[] = $source;
        }

        if ($localPort !== null) {
            return $sources[0] ?? null;
        }

        return count($sources) === 1 ? $sources[0] : null;
    }

    /**
     * @param  array<int, string>  $interfaces
     * @param  array<int, string>  $interfacesToRemove
     */
    public function configureBridge(
        Router $router,
        string $bridgeName,
        array $interfaces,
        string $correlationId,
        array $interfacesToRemove = [],
    ): void {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $bridgeName) !== 1) {
            throw RouterProbeException::configuration('The bridge name must use 1 to 32 letters, numbers, dots, underscores, or hyphens.');
        }

        foreach ([$interfaces, $interfacesToRemove] as $selection) {
            foreach ($selection as $interface) {
                if (! is_string($interface) || $interface === '') {
                    throw RouterProbeException::configuration('Every selected interface must have a valid name.');
                }
            }
        }

        if ($interfaces === [] && $interfacesToRemove === []) {
            throw RouterProbeException::configuration('Select at least one Ethernet interface to add or remove.');
        }

        if (
            count(array_unique($interfaces)) !== count($interfaces)
            || count(array_unique($interfacesToRemove)) !== count($interfacesToRemove)
            || array_intersect($interfaces, $interfacesToRemove) !== []
        ) {
            throw RouterProbeException::configuration(
                'Bridge additions and removals must be distinct Ethernet interfaces.'
            );
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
        $bridgePortIdsByInterface = [];

        foreach ($bridgePorts as $bridgePort) {
            $interface = $this->stringOrNull($bridgePort['interface'] ?? null);
            $bridge = $this->stringOrNull($bridgePort['bridge'] ?? null);
            $portId = $this->stringOrNull($bridgePort['.id'] ?? null);

            if ($interface !== null && $bridge !== null) {
                $bridgeByInterface[$interface] = $bridge;
                $bridgePortIdsByInterface[$interface] = $portId;
            }
        }

        foreach ($interfaces as $interface) {
            if (! in_array($interfaceTypes[$interface] ?? null, ['ether', 'ethernet'], true)) {
                throw RouterProbeException::configuration(
                    'A selected interface to add is no longer an available Ethernet interface.'
                );
            }

            $currentBridge = $bridgeByInterface[$interface] ?? null;

            if ($currentBridge !== null && $currentBridge !== $bridgeName) {
                throw RouterProbeException::configuration(
                    'A selected interface already belongs to a different bridge. Remove it from that bridge before continuing.'
                );
            }
        }

        foreach ($interfacesToRemove as $interface) {
            if (! in_array($interfaceTypes[$interface] ?? null, ['ether', 'ethernet'], true)) {
                throw RouterProbeException::configuration(
                    'A selected interface to remove is no longer an available Ethernet interface.'
                );
            }

            if (($bridgeByInterface[$interface] ?? null) !== $bridgeName) {
                throw RouterProbeException::configuration(
                    'A selected interface to remove no longer belongs to the requested bridge.'
                );
            }

            if (! filled($bridgePortIdsByInterface[$interface] ?? null)) {
                throw RouterProbeException::configuration(
                    'RouterOS did not return the bridge port identifier needed for removal.'
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

        foreach ($interfacesToRemove as $interface) {
            $portId = (string) $bridgePortIdsByInterface[$interface];

            if (preg_match('/\A\*[A-Za-z0-9]+\z/', $portId) !== 1) {
                throw RouterProbeException::configuration(
                    'RouterOS returned an invalid bridge port identifier for removal.'
                );
            }

            $this->delete(
                $client,
                $router,
                '/rest/interface/bridge/port/'.$portId,
                $correlationId,
            );
        }

        $verifiedInterfaces = $this->getRows($client, $router, '/rest/interface', $correlationId);
        $verifiedBridgeExists = false;

        foreach ($verifiedInterfaces as $verifiedInterface) {
            if (
                $this->stringOrNull($verifiedInterface['name'] ?? null) === $bridgeName
                && strtolower((string) ($verifiedInterface['type'] ?? '')) === 'bridge'
            ) {
                $verifiedBridgeExists = true;
                break;
            }
        }

        if (! $verifiedBridgeExists) {
            throw RouterProbeException::configuration(
                "RouterOS did not confirm that bridge {$bridgeName} exists."
            );
        }

        $verifiedBridgeByInterface = [];

        foreach ($this->getRows($client, $router, '/rest/interface/bridge/port', $correlationId) as $bridgePort) {
            $interface = $this->stringOrNull($bridgePort['interface'] ?? null);
            $bridge = $this->stringOrNull($bridgePort['bridge'] ?? null);

            if ($interface !== null && $bridge !== null) {
                $verifiedBridgeByInterface[$interface] = $bridge;
            }
        }

        foreach ($interfaces as $interface) {
            if (($verifiedBridgeByInterface[$interface] ?? null) !== $bridgeName) {
                throw RouterProbeException::configuration(
                    "RouterOS did not confirm that {$interface} was added to {$bridgeName}."
                );
            }
        }

        foreach ($interfacesToRemove as $interface) {
            if (($verifiedBridgeByInterface[$interface] ?? null) === $bridgeName) {
                throw RouterProbeException::configuration(
                    "RouterOS did not confirm that {$interface} was removed from {$bridgeName}."
                );
            }
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
            $this->recordFailure($router, 'GET', '/rest/ip/hotspot/profile', $started, $exception, $correlationId, $client);

            throw $this->asProbeException($exception, $client);
        }
    }

    public function routerInterfaces(Router $router, string $correlationId): array
    {
        $rows = $this->getRows(
            $this->connections->make($router),
            $router,
            '/rest/interface',
            $correlationId,
        );
        $interfaces = [];

        foreach ($rows as $row) {
            $name = $this->stringOrNull($row['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $interfaces[] = [
                'name' => $name,
                'type' => strtolower($this->stringOrNull($row['type'] ?? null) ?? ''),
                'running' => $this->routerOsBoolean($row['running'] ?? false),
                'disabled' => $this->routerOsBoolean($row['disabled'] ?? false),
            ];
        }

        usort(
            $interfaces,
            fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']),
        );

        return $interfaces;
    }

    public function ipPools(Router $router, string $correlationId): array
    {
        $rows = $this->getRows(
            $this->connections->make($router),
            $router,
            '/rest/ip/pool',
            $correlationId,
        );
        $pools = [];

        foreach ($rows as $row) {
            $name = $this->stringOrNull($row['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $pools[] = [
                'name' => $name,
                'ranges' => $this->stringOrNull($row['ranges'] ?? null),
            ];
        }

        usort(
            $pools,
            fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']),
        );

        return $pools;
    }

    public function hotspotServers(Router $router, string $correlationId): array
    {
        $rows = $this->getRows(
            $this->connections->make($router),
            $router,
            '/rest/ip/hotspot',
            $correlationId,
        );
        $servers = [];
        $managedName = HotspotServerConfiguration::nameFromRouter($router);

        foreach ($rows as $row) {
            $name = $this->stringOrNull($row['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $servers[] = [
                'id' => $this->stringOrNull($row['.id'] ?? null),
                'name' => $name,
                'interface' => $this->stringOrNull($row['interface'] ?? null),
                'address_pool' => $this->stringOrNull($row['address-pool'] ?? null),
                'profile' => $this->stringOrNull($row['profile'] ?? null),
                'disabled' => $this->routerOsBoolean($row['disabled'] ?? false),
                'managed' => $name === $managedName,
            ];
        }

        usort(
            $servers,
            fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']),
        );

        return $servers;
    }

    public function configureHotspotServer(Router $router, array $configuration, string $correlationId): array
    {
        $configuration['server_name'] = HotspotServerConfiguration::nameFromRouter($router);
        $configuration['address_pool'] = HotspotServerConfiguration::addressPoolFromRouter($router);
        $configuration = HotspotServerConfiguration::normalize($configuration);
        $desired = HotspotServerConfiguration::desiredSettings($configuration);
        $client = $this->connections->make($router);
        $endpoint = '/rest/ip/hotspot';
        $servers = $this->getRows($client, $router, $endpoint, $correlationId);

        // RouterOS 7 does not expose a comment field on /ip/hotspot, so the
        // server name is derived from the router identity. An existing server
        // with the same name on the same interface is updated; anything else
        // is left alone.
        $managed = array_values(array_filter(
            $servers,
            fn (array $server): bool => ($server['name'] ?? null) === $desired['name'],
        ));

        if (count($managed) > 1) {
            throw RouterProbeException::configuration('Duplicate HotSpot servers with the requested name were found. Review the router before retrying.');
        }

        $managedId = $managed === [] ? null : $this->stringOrNull($managed[0]['.id'] ?? null);
        $onInterface = array_values(array_filter(
            $servers,
            fn (array $server): bool => ($server['interface'] ?? null) === $desired['interface'],
        ));

        if ($managedId !== null && ($managed[0]['interface'] ?? null) !== $desired['interface']) {
            throw RouterProbeException::configuration(
                "A HotSpot server named {$desired['name']} already exists on another interface. It was not changed."
            );
        }

        if ($onInterface !== [] && ($managedId === null || ($onInterface[0]['.id'] ?? null) !== $managedId)) {
            throw RouterProbeException::configuration(
                "A HotSpot server already exists on interface {$desired['interface']}. Review it before configuring another server."
            );
        }

        if ($managedId !== null) {
            if (! $this->hotspotServerMatches($managed[0], $desired)) {
                if (preg_match('/\A[\w.*-]+\z/D', $managedId) !== 1) {
                    throw RouterProbeException::configuration('The HotSpot server cannot be updated because RouterOS returned an invalid identifier.');
                }

                $this->patch(
                    $client,
                    $router,
                    $endpoint.'/'.$managedId,
                    $desired,
                    $desired['name'],
                    $correlationId,
                );
            }
        } else {
            $this->put($client, $router, $endpoint, $desired, $correlationId);
        }

        $updated = $this->getRows($client, $router, $endpoint, $correlationId);
        $managedAfter = array_values(array_filter(
            $updated,
            fn (array $server): bool => ($server['name'] ?? null) === $desired['name'],
        ));
        $profiles = $this->getRows($client, $router, '/rest/ip/hotspot/profile', $correlationId);
        $interfaces = $this->getRows($client, $router, '/rest/interface', $correlationId);
        $profileExists = collect($profiles)->contains(
            fn (array $profile): bool => ($profile['name'] ?? null) === $desired['profile'],
        );
        $interfaceExists = collect($interfaces)->contains(
            fn (array $interface): bool => ($interface['name'] ?? null) === $desired['interface'],
        );

        return [
            'hotspot_server' => $this->check(
                $this->findHotspotServerRow($updated, $desired) !== null,
                'RouterOS HotSpot server name, interface, pool, and profile were read back from the router identity.'
            ),
            'hotspot_server_unique' => $this->check(
                count($managedAfter) === 1,
                'Exactly one HotSpot server with the router identity name is present.',
            ),
            'hotspot_server_profile' => $this->check(
                $profileExists,
                'The selected HotSpot server profile exists on the router.',
            ),
            'hotspot_server_interface' => $this->check(
                $interfaceExists,
                'The selected HotSpot interface exists on the router.',
            ),
        ];
    }

    public function enableHotspotCookieLogin(Router $router, string $correlationId): void
    {
        $client = $this->connections->make($router);
        $endpoint = '/rest/ip/hotspot/profile';
        $profiles = $this->getRows($client, $router, $endpoint, $correlationId);

        if ($profiles === []) {
            throw RouterProbeException::configuration('No HotSpot server profiles were found on this router.');
        }

        $profileNames = [];

        foreach ($profiles as $profile) {
            $profileId = $this->stringOrNull($profile['.id'] ?? null);
            $profileName = $this->stringOrNull($profile['name'] ?? null);
            $currentLoginBy = $profile['login-by'] ?? '';

            if (
                $profileId === null
                || preg_match('/\A[\w.*-]+\z/D', $profileId) !== 1
                || $profileName === null
                || ! is_string($currentLoginBy)
            ) {
                throw RouterProbeException::configuration('A RouterOS HotSpot server profile has invalid or incomplete settings.');
            }

            $profileNames[] = $profileName;
            $loginMethods = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $currentLoginBy)),
                fn (string $method): bool => $method !== '',
            )));

            if (array_intersect(['http-chap', 'http-pap', 'https'], $loginMethods) === []) {
                $loginMethods[] = 'http-chap';
            }

            if (! in_array('cookie', $loginMethods, true)) {
                $loginMethods[] = 'cookie';
            }

            $payload = [
                'login-by' => implode(',', $loginMethods),
                'http-cookie-lifetime' => self::HOTSPOT_COOKIE_LIFETIME,
            ];
            $currentCookieLifetimeSeconds = $this->parseUptime(
                $this->stringOrNull($profile['http-cookie-lifetime'] ?? null),
            );

            if (
                $payload['login-by'] !== $currentLoginBy
                || $currentCookieLifetimeSeconds === null
                || $currentCookieLifetimeSeconds < self::HOTSPOT_COOKIE_LIFETIME_SECONDS
            ) {
                $this->patch(
                    $client,
                    $router,
                    $endpoint.'/'.$profileId,
                    $payload,
                    $profileName,
                    $correlationId,
                );
            }
        }

        $updatedProfiles = $this->getRows($client, $router, $endpoint, $correlationId);
        $updatedProfileNames = array_map(
            fn (array $profile): string => $this->stringOrNull($profile['name'] ?? null) ?? '',
            $updatedProfiles,
        );
        sort($profileNames, SORT_STRING);
        sort($updatedProfileNames, SORT_STRING);

        if ($updatedProfileNames !== $profileNames) {
            throw RouterProbeException::configuration('RouterOS did not confirm all HotSpot server profiles after cookie login was configured.');
        }

        foreach ($updatedProfiles as $profile) {
            $loginBy = $profile['login-by'] ?? null;
            $loginMethods = is_string($loginBy)
                ? array_map('trim', explode(',', $loginBy))
                : [];
            $cookieLifetimeSeconds = $this->parseUptime(
                $this->stringOrNull($profile['http-cookie-lifetime'] ?? null),
            );

            if (
                ! in_array('cookie', $loginMethods, true)
                || array_intersect(['http-chap', 'http-pap', 'https'], $loginMethods) === []
                || $cookieLifetimeSeconds === null
                || $cookieLifetimeSeconds < self::HOTSPOT_COOKIE_LIFETIME_SECONDS
            ) {
                $profileName = $this->stringOrNull($profile['name'] ?? null) ?? 'unknown';
                throw RouterProbeException::configuration(
                    "RouterOS did not confirm cookie-based login settings for HotSpot server profile {$profileName}."
                );
            }
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
                'id' => $this->stringOrNull($profile['.id'] ?? null),
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

    public function activeHotspotSessions(Router $router, string $correlationId): array
    {
        $rows = $this->getRows(
            $this->connections->make($router),
            $router,
            '/rest/ip/hotspot/active',
            $correlationId,
        );
        $sessions = [];

        foreach ($rows as $row) {
            $username = $this->stringOrNull($row['user'] ?? null)
                ?? $this->stringOrNull($row['name'] ?? null);

            if ($username === null) {
                continue;
            }

            $rxBytes = $this->intOrNull($row['bytes-in'] ?? null)
                ?? $this->intOrNull($row['rx-bytes'] ?? null);
            $txBytes = $this->intOrNull($row['bytes-out'] ?? null)
                ?? $this->intOrNull($row['tx-bytes'] ?? null);

            $sessions[] = [
                'id' => $this->stringOrNull($row['.id'] ?? null),
                'username' => $username,
                'ip_address' => $this->stringOrNull($row['address'] ?? null),
                'mac_address' => $this->stringOrNull($row['mac-address'] ?? null),
                'connected_seconds' => $this->parseUptime($this->stringOrNull($row['uptime'] ?? null)),
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'total_bytes' => max(0, (int) ($rxBytes ?? 0)) + max(0, (int) ($txBytes ?? 0)),
            ];
        }

        return $sessions;
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

        try {
            $rateLimit = HotspotRateLimit::canonical($rateLimit);
        } catch (InvalidArgumentException $exception) {
            throw RouterProbeException::configuration(
                'The Hotspot profile rate limit is invalid: '.$exception->getMessage()
            );
        }

        $expectedSpeeds = null;

        if ($rateLimit !== null) {
            $expectedSpeeds = HotspotRateLimit::speedsKbps($rateLimit);
        }

        $client = $this->connections->make($router);
        $endpoint = '/rest/ip/hotspot/user/profile';
        $profiles = $this->getRows($client, $router, $endpoint, $correlationId);
        $existing = $this->findHotspotUserProfile($profiles, $profileName);

        if ($existing !== null) {
            $existingRateLimit = $this->stringOrNull($existing['rate-limit'] ?? null);
            $existingSharedUsers = $this->stringOrNull($existing['shared-users'] ?? null) ?? '1';

            $existingSpeeds = null;

            if ($existingRateLimit !== null) {
                try {
                    $existingSpeeds = HotspotRateLimit::speedsKbps($existingRateLimit);
                } catch (InvalidArgumentException) {
                    $existingSpeeds = null;
                }
            }

            if ($existingSpeeds !== $expectedSpeeds || $existingSharedUsers !== (string) $sharedUsers) {
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

        $createdRateLimit = $createdProfile === null
            ? null
            : $this->stringOrNull($createdProfile['rate-limit'] ?? null);
        $createdSpeeds = null;

        if ($createdRateLimit !== null) {
            try {
                $createdSpeeds = HotspotRateLimit::speedsKbps($createdRateLimit);
            } catch (InvalidArgumentException) {
                $createdSpeeds = null;
            }
        }

        if (
            $createdProfile === null
            || $createdSpeeds !== $expectedSpeeds
            || ($this->stringOrNull($createdProfile['shared-users'] ?? null) ?? '1') !== (string) $sharedUsers
        ) {
            throw RouterProbeException::configuration(
                "RouterOS did not confirm the requested Hotspot user profile settings for {$profileName}."
            );
        }
    }

    public function updateHotspotUserProfile(
        Router $router,
        string $profileId,
        string $profileName,
        ?string $expectedRateLimit,
        int $expectedSharedUsers,
        ?string $rateLimit,
        int $sharedUsers,
        string $correlationId,
    ): void {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $profileName) !== 1) {
            throw RouterProbeException::configuration('The Hotspot profile name must use 1 to 64 letters, numbers, dots, underscores, or hyphens.');
        }

        if (preg_match('/\A\*[0-9A-Fa-f]+\z/', $profileId) !== 1) {
            throw RouterProbeException::configuration('The Hotspot profile identifier is invalid.');
        }

        if ($sharedUsers < 1 || $expectedSharedUsers < 1) {
            throw RouterProbeException::configuration('The Hotspot profile shared-user limit must be at least one.');
        }

        try {
            $expectedSpeeds = $this->hotspotProfileSpeeds($expectedRateLimit);
            $desiredSpeeds = $this->hotspotProfileSpeeds($rateLimit);
            $desiredRateLimit = HotspotRateLimit::canonical($rateLimit);
        } catch (RouterProbeException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw RouterProbeException::configuration(
                'The Hotspot profile rate limit is invalid: '.$exception->getMessage()
            );
        }

        $client = $this->connections->make($router);
        $endpoint = '/rest/ip/hotspot/user/profile';
        $profiles = $this->getRows($client, $router, $endpoint, $correlationId);
        $current = $this->findHotspotUserProfileById($profiles, $profileId, $profileName);

        if ($current === null) {
            throw RouterProbeException::configuration(
                "The Hotspot user profile {$profileName} no longer exists on this router. Refresh the profile list and try again."
            );
        }

        $currentSpeeds = $this->hotspotProfileSpeeds($this->stringOrNull($current['rate-limit'] ?? null));
        $currentSharedUsers = $this->stringOrNull($current['shared-users'] ?? null) ?? '1';

        if (
            $currentSpeeds !== $expectedSpeeds
            || $currentSharedUsers !== (string) $expectedSharedUsers
        ) {
            throw RouterProbeException::configuration(
                "The Hotspot user profile {$profileName} changed after it was loaded. Refresh the profile list and try again."
            );
        }

        if ($desiredSpeeds === $currentSpeeds && (string) $sharedUsers === $currentSharedUsers) {
            return;
        }

        $payload = [
            'shared-users' => (string) $sharedUsers,
            'rate-limit' => $desiredRateLimit ?? '',
        ];

        $this->patch(
            $client,
            $router,
            $endpoint.'/'.$profileId,
            $payload,
            $profileName,
            $correlationId,
        );

        $profiles = $this->getRows($client, $router, $endpoint, $correlationId);
        $updated = $this->findHotspotUserProfileById($profiles, $profileId, $profileName);
        $updatedSpeeds = $updated === null
            ? null
            : $this->hotspotProfileSpeeds($this->stringOrNull($updated['rate-limit'] ?? null));
        $updatedSharedUsers = $updated === null
            ? null
            : ($this->stringOrNull($updated['shared-users'] ?? null) ?? '1');

        if (
            $updated === null
            || $updatedSpeeds !== $desiredSpeeds
            || $updatedSharedUsers !== (string) $sharedUsers
        ) {
            throw RouterProbeException::configuration(
                "RouterOS did not confirm the requested Hotspot user profile settings for {$profileName}."
            );
        }
    }

    public function exportConfiguration(Router $router, string $fileName, string $correlationId): array
    {
        $this->assertConfigurationFileName($fileName);

        $client = $this->connections->make($router);
        $baseName = substr($fileName, 0, -4);
        $checks = [];

        $this->postRows(
            $client,
            $router,
            '/rest/export',
            ['file' => $baseName],
            $correlationId,
        );

        $checks['export'] = $this->check(true, 'RouterOS accepted the configuration export request.');

        $contents = '';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $contents = $this->readRemoteFile($client, $router, $fileName, $correlationId);

            if ($contents !== '') {
                break;
            }

            usleep(200_000);
        }

        if ($contents === '') {
            $checks['read_back'] = $this->check(false, 'The RouterOS export did not produce any content.');

            throw RouterProbeException::configuration(
                'The RouterOS configuration export did not produce any content.'
            );
        }

        $checks['read_back'] = $this->check(
            true,
            sprintf('Read back %s bytes of RouterOS configuration.', number_format(strlen($contents))),
        );

        try {
            $this->deleteConfigurationFile($router, $fileName, $correlationId);
            $checks['temporary_file_removed'] = $this->check(
                true,
                'The temporary RouterOS export file was removed.',
            );
        } catch (Throwable $exception) {
            $checks['temporary_file_removed'] = $this->check(
                false,
                'The temporary RouterOS export file could not be removed: '.$exception->getMessage(),
            );

            throw $exception;
        }

        return [
            'contents' => $contents,
            'checks' => $checks,
            'file_name' => $fileName,
        ];
    }

    public function uploadConfigurationFile(
        Router $router,
        string $fileName,
        string $contents,
        string $correlationId,
    ): void {
        $this->assertConfigurationFileName($fileName);

        if ($contents === '') {
            throw RouterProbeException::configuration('The uploaded .rsc file is empty.');
        }

        if (strlen($contents) > self::MAX_IMPORT_FILE_BYTES) {
            throw RouterProbeException::configuration(
                'RouterOS accepts .rsc uploads up to '.self::MAX_IMPORT_FILE_BYTES.' bytes. Upload a smaller file.'
            );
        }

        $client = $this->connections->make($router);

        if ($this->fileExists($client, $router, $fileName, $correlationId)) {
            $this->deleteConfigurationFile($router, $fileName, $correlationId);
        }

        $this->put(
            $client,
            $router,
            '/rest/file',
            ['name' => $fileName, 'contents' => $contents],
            $correlationId,
        );

        if ($this->readRemoteFile($client, $router, $fileName, $correlationId) !== $contents) {
            throw RouterProbeException::configuration(
                'RouterOS did not read back the uploaded .rsc file exactly as sent.'
            );
        }
    }

    public function importConfigurationFile(
        Router $router,
        string $fileName,
        bool $dryRun,
        string $correlationId,
    ): array {
        $this->assertConfigurationFileName($fileName);

        $client = $this->connections->make($router);

        if (! $this->fileExists($client, $router, $fileName, $correlationId)) {
            throw RouterProbeException::configuration(
                'The uploaded .rsc file is no longer present on this router.'
            );
        }

        $payload = ['file-name' => $fileName, 'verbose' => 'yes'];

        if ($dryRun) {
            $payload['dry-run'] = '';
        }

        $rows = $this->postRows($client, $router, '/rest/import', $payload, $correlationId);
        $messages = $this->importMessages($rows);
        $joined = implode("\n", $messages);

        if ($dryRun) {
            if (stripos($joined, 'no syntax errors found') === false || $this->importHasErrors($messages)) {
                throw RouterProbeException::configuration(
                    'RouterOS did not confirm that the .rsc file is syntactically valid.'
                    .($joined === '' ? '' : ' RouterOS said: '.$joined)
                );
            }

            return [
                'messages' => $messages,
                'checks' => [
                    'syntax' => $this->check(
                        true,
                        'RouterOS confirmed that the .rsc file has no syntax errors.',
                    ),
                ],
            ];
        }

        if ($this->importHasErrors($messages)) {
            throw RouterProbeException::configuration(
                'RouterOS reported an error while importing the .rsc file: '.$joined
            );
        }

        return [
            'messages' => $messages,
            'checks' => [
                'import' => $this->check(
                    true,
                    'RouterOS completed the .rsc import without reporting an error.',
                ),
            ],
        ];
    }

    public function deleteConfigurationFile(Router $router, string $fileName, string $correlationId): void
    {
        $this->assertConfigurationFileName($fileName);

        $client = $this->connections->make($router);

        if (! $this->fileExists($client, $router, $fileName, $correlationId)) {
            return;
        }

        $this->delete($client, $router, '/rest/file/'.rawurlencode($fileName), $correlationId);

        if ($this->fileExists($client, $router, $fileName, $correlationId)) {
            throw RouterProbeException::configuration(
                'The temporary RouterOS file still exists after deletion.'
            );
        }
    }

    public function resetManagedConfiguration(
        Router $router,
        string $expectedIdentity,
        string $bridgeName,
        array $managedInterfaces,
        array $managedProfileNames,
        string $correlationId,
    ): array {
        $identity = RouterIdentity::normalize($expectedIdentity, $router->name);

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $bridgeName) !== 1) {
            throw RouterProbeException::configuration('The bridge name is invalid.');
        }

        foreach ($managedInterfaces as $interface) {
            if (! is_string($interface) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $interface) !== 1) {
                throw RouterProbeException::configuration('A managed bridge interface name is invalid.');
            }
        }

        foreach ($managedProfileNames as $profileName) {
            if (! is_string($profileName)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $profileName) !== 1) {
                throw RouterProbeException::configuration('A managed HotSpot profile name is invalid.');
            }
        }

        $managedInterfaces = array_values(array_unique($managedInterfaces));
        $managedProfileNames = array_values(array_unique([$identity, ...$managedProfileNames]));
        $client = $this->connections->make($router);
        $checks = [];
        $managedAddressCount = $this->matchingRowCount(
            $this->getRows($client, $router, '/rest/ip/address', $correlationId),
            fn (array $row): bool => ($this->stringOrNull($row['comment'] ?? null) ?? '')
                === self::MANAGED_ADDRESS_COMMENT,
        );

        $this->setIdentity($router, $identity, $correlationId);
        $checks['identity'] = $this->check(true, 'The router identity was restored to '.$identity.'.');

        $this->deleteRowsMatching(
            $client,
            $router,
            '/rest/ip/hotspot',
            $this->getRows($client, $router, '/rest/ip/hotspot', $correlationId),
            fn (array $row): bool => $this->stringOrNull($row['name'] ?? null) === $identity,
            'HotSpot server',
            $correlationId,
        );

        $this->deleteRowsMatching(
            $client,
            $router,
            '/rest/ip/hotspot/user/profile',
            $this->getRows($client, $router, '/rest/ip/hotspot/user/profile', $correlationId),
            fn (array $row): bool => in_array(
                $this->stringOrNull($row['name'] ?? null),
                $managedProfileNames,
                true,
            ),
            'HotSpot user profile',
            $correlationId,
        );

        $networkResources = [
            ['/rest/ip/dhcp-server', self::MANAGED_DHCP_SERVER_COMMENT, 'DHCP server'],
            ['/rest/ip/dhcp-server/network', self::MANAGED_DHCP_NETWORK_COMMENT, 'DHCP network'],
            ['/rest/ip/firewall/nat', self::MANAGED_NAT_COMMENT, 'NAT rule'],
        ];

        foreach ($networkResources as [$endpoint, $comment, $label]) {
            $this->deleteRowsMatching(
                $client,
                $router,
                $endpoint,
                $this->getRows($client, $router, $endpoint, $correlationId),
                fn (array $row): bool => ($this->stringOrNull($row['comment'] ?? null) ?? '') === $comment,
                $label,
                $correlationId,
            );
        }

        $this->deleteRowsMatching(
            $client,
            $router,
            '/rest/ip/firewall/filter',
            $this->getRows($client, $router, '/rest/ip/firewall/filter', $correlationId),
            fn (array $row): bool => $this->isManagedFirewallRow($row),
            'firewall rule',
            $correlationId,
        );

        $this->deleteRowsMatching(
            $client,
            $router,
            '/rest/interface/bridge/port',
            $this->getRows($client, $router, '/rest/interface/bridge/port', $correlationId),
            fn (array $row): bool => $this->stringOrNull($row['bridge'] ?? null) === $bridgeName
                && in_array($this->stringOrNull($row['interface'] ?? null), $managedInterfaces, true),
            'bridge port',
            $correlationId,
        );

        $this->deleteRowsMatching(
            $client,
            $router,
            '/rest/ip/pool',
            $this->getRows($client, $router, '/rest/ip/pool', $correlationId),
            fn (array $row): bool => ($this->stringOrNull($row['comment'] ?? null) ?? '') === self::MANAGED_POOL_COMMENT,
            'IP pool',
            $correlationId,
        );

        $checks['managed_entries_removed'] = $this->check(
            true,
            'MMUTech-managed objects were removed; management firewall rules and voucher users were preserved.',
        );

        $checks['identity_verified'] = $this->check(
            $this->readIdentity($client, $router, $correlationId) === $identity,
            'RouterOS read back the expected identity.',
        );

        $checks['hotspot_removed'] = $this->check(
            $this->matchingRowCount(
                $this->getRows($client, $router, '/rest/ip/hotspot', $correlationId),
                fn (array $row): bool => $this->stringOrNull($row['name'] ?? null) === $identity,
            ) === 0,
            'The MMUTech-managed HotSpot server is no longer present.',
        );

        $checks['hotspot_profiles_removed'] = $this->check(
            $this->matchingRowCount(
                $this->getRows($client, $router, '/rest/ip/hotspot/user/profile', $correlationId),
                fn (array $row): bool => in_array(
                    $this->stringOrNull($row['name'] ?? null),
                    $managedProfileNames,
                    true,
                ),
            ) === 0,
            'The MMUTech-managed HotSpot user profiles are no longer present.',
        );

        $networkChecks = [];

        foreach ($networkResources as [$endpoint, $comment, $label]) {
            $networkChecks[$endpoint] = $this->matchingRowCount(
                $this->getRows($client, $router, $endpoint, $correlationId),
                fn (array $row): bool => ($this->stringOrNull($row['comment'] ?? null) ?? '') === $comment,
            );
        }

        $checks['network_removed'] = $this->check(
            array_sum($networkChecks) === 0,
            'MMUTech-managed DHCP and NAT entries are no longer present.',
        );

        $checks['lan_address_preserved'] = $this->check(
            $this->matchingRowCount(
                $this->getRows($client, $router, '/rest/ip/address', $correlationId),
                fn (array $row): bool => ($this->stringOrNull($row['comment'] ?? null) ?? '')
                    === self::MANAGED_ADDRESS_COMMENT,
            ) === $managedAddressCount,
            'The MMUTech-managed LAN address was preserved so management access remains available.',
        );

        $checks['firewall_removed'] = $this->check(
            $this->matchingRowCount(
                $this->getRows($client, $router, '/rest/ip/firewall/filter', $correlationId),
                fn (array $row): bool => $this->isManagedFirewallRow($row),
            ) === 0,
            'MMUTech-managed firewall rules are no longer present. Management access rules remain.',
        );

        $checks['bridge_members_removed'] = $this->check(
            $this->matchingRowCount(
                $this->getRows($client, $router, '/rest/interface/bridge/port', $correlationId),
                fn (array $row): bool => $this->stringOrNull($row['bridge'] ?? null) === $bridgeName
                    && in_array($this->stringOrNull($row['interface'] ?? null), $managedInterfaces, true),
            ) === 0,
            'MMUTech-managed LAN bridge members are no longer present.',
        );

        $checks['pool_removed'] = $this->check(
            $this->matchingRowCount(
                $this->getRows($client, $router, '/rest/ip/pool', $correlationId),
                fn (array $row): bool => ($this->stringOrNull($row['comment'] ?? null) ?? '') === self::MANAGED_POOL_COMMENT,
            ) === 0,
            'The MMUTech-managed LAN pool is no longer present.',
        );

        $checks['vouchers_preserved'] = $this->check(
            true,
            'RouterOS HotSpot users were not modified.',
        );

        return $checks;
    }

    private function assertConfigurationFileName(string $fileName): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,95}\.rsc\z/', $fileName) !== 1) {
            throw RouterProbeException::configuration('The configuration file name is invalid.');
        }
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function postRows(
        Client $client,
        Router $router,
        string $endpoint,
        array $payload,
        string $correlationId,
    ): array {
        $started = hrtime(true);

        try {
            $rows = $client->post($endpoint, $payload);

            $this->recorder->success(
                $router,
                'POST',
                $endpoint,
                $this->elapsedMs($started),
                200,
                ['fields' => array_keys($payload)],
                ['count' => is_array($rows) ? count($rows) : 0],
                $correlationId,
            );

            if (! is_array($rows)) {
                return [];
            }

            return array_is_list($rows) ? $rows : [$rows];
        } catch (Throwable $exception) {
            $this->recordFailure(
                $router,
                'POST',
                $endpoint,
                $started,
                $exception,
                $correlationId,
                $client,
                ['fields' => array_keys($payload)],
            );

            throw $this->asProbeException($exception, $client);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readRemoteFile(
        Client $client,
        Router $router,
        string $fileName,
        string $correlationId,
    ): string {
        $contents = '';
        $offset = 0;

        while (true) {
            $rows = $this->postRows(
                $client,
                $router,
                '/rest/file/read',
                [
                    'file' => $fileName,
                    'chunk-size' => (string) self::FILE_READ_CHUNK_BYTES,
                    'offset' => (string) $offset,
                ],
                $correlationId,
            );

            $chunk = $this->stringOrNull($rows[0]['data'] ?? null) ?? '';

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
            $offset += strlen($chunk);

            if ($offset > self::MAX_EXPORT_FILE_BYTES) {
                throw RouterProbeException::configuration(
                    'The RouterOS configuration file exceeds the supported download size.'
                );
            }

            if (strlen($chunk) < self::FILE_READ_CHUNK_BYTES) {
                break;
            }
        }

        return $contents;
    }

    private function fileExists(
        Client $client,
        Router $router,
        string $fileName,
        string $correlationId,
    ): bool {
        $rows = $this->getRows(
            $client,
            $router,
            '/rest/file?name='.rawurlencode($fileName),
            $correlationId,
        );

        foreach ($rows as $row) {
            if ($this->stringOrNull($row['name'] ?? null) === $fileName) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function isManagedFirewallRow(array $row): bool
    {
        $comment = $this->stringOrNull($row['comment'] ?? null) ?? '';

        if (! str_starts_with($comment, self::MANAGED_FIREWALL_COMMENT_PREFIX)) {
            return false;
        }

        return ! str_starts_with($comment, self::MANAGED_FIREWALL_COMMENT_PREFIX.'input management ');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool  $predicate
     */
    private function deleteRowsMatching(
        Client $client,
        Router $router,
        string $endpoint,
        array $rows,
        callable $predicate,
        string $label,
        string $correlationId,
    ): int {
        $deleted = 0;

        foreach ($rows as $row) {
            if (! $predicate($row)) {
                continue;
            }

            $id = $this->stringOrNull($row['.id'] ?? null);

            if ($id === null) {
                throw RouterProbeException::configuration(
                    'A managed '.$label.' could not be removed because RouterOS returned no identifier.'
                );
            }

            $this->delete(
                $client,
                $router,
                $endpoint.'/'.rawurlencode($id),
                $correlationId,
            );
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool  $predicate
     */
    private function matchingRowCount(array $rows, callable $predicate): int
    {
        $count = 0;

        foreach ($rows as $row) {
            if ($predicate($row)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function importMessages(array $rows): array
    {
        $messages = [];

        foreach ($rows as $row) {
            foreach (['ret', 'message', 'output', 'data'] as $key) {
                $message = $this->stringOrNull($row[$key] ?? null);

                if ($message !== null && $message !== '') {
                    $messages[] = $message;
                }
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param  array<int, string>  $messages
     */
    private function importHasErrors(array $messages): bool
    {
        foreach ($messages as $message) {
            if (preg_match('/\berror\b|\bfailed\b|\bfailure\b|\binvalid\b/i', $message) === 1) {
                return true;
            }
        }

        return false;
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
            $this->recordFailure($router, 'GET', '/rest/system/identity', $started, $exception, $correlationId, $client);

            throw $this->asProbeException($exception, $client);
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
            $this->recordFailure($router, 'GET', '/rest/system/resource', $started, $exception, $correlationId, $client);

            throw $this->asProbeException($exception, $client);
        }
    }

    private function recordFailure(
        Router $router,
        string $method,
        string $endpoint,
        int $started,
        Throwable $exception,
        string $correlationId,
        ?Client $client = null,
        array $requestMeta = [],
    ): void {
        $probeException = $this->asProbeException($exception, $client);
        $httpStatus = null;

        if ($exception instanceof MikrotikException) {
            $code = $exception->getCode();

            if (is_int($code) && $code >= 400 && $code <= 599) {
                $httpStatus = $code;
            }
        }

        $responseMeta = [];
        $routerOsError = $client instanceof DetailedMikrotikClient
            ? $client->lastRouterOsError()
            : null;

        if ($routerOsError !== null && $routerOsError !== '') {
            $responseMeta['routeros_error'] = $routerOsError;
        }

        $this->recorder->failure(
            $router,
            $method,
            $endpoint,
            $this->elapsedMs($started),
            $probeException->category,
            $probeException->getMessage(),
            $httpStatus,
            $requestMeta,
            $responseMeta,
            $correlationId
        );
    }

    private function asProbeException(Throwable $exception, ?Client $client = null): RouterProbeException
    {
        if ($exception instanceof RouterProbeException) {
            return $exception;
        }

        $routerOsError = $client instanceof DetailedMikrotikClient
            ? $client->lastRouterOsError()
            : null;

        return RouterProbeException::fromThrowable($exception, $routerOsError);
    }

    private function elapsedMs(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }

    /**
     * @param  callable(array<string, mixed>): ?array{id: ?string, title: string, detail: string, comment: ?string, disabled: bool, managed: bool}  $mapper
     * @return array<int, array{id: ?string, title: string, detail: string, comment: ?string, disabled: bool, managed: bool}>
     */
    private function discoverNetworkRows(
        Client $client,
        Router $router,
        string $endpoint,
        string $correlationId,
        callable $mapper,
    ): array {
        $rows = [];

        foreach ($this->getRows($client, $router, $endpoint, $correlationId) as $row) {
            $mapped = $mapper($row);

            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        usort(
            $rows,
            fn (array $left, array $right): int => strnatcasecmp($left['title'], $right['title']),
        );

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: ?string, title: string, detail: string, comment: ?string, disabled: bool, managed: bool}
     */
    private function networkRow(array $row, string $title, string $detail): array
    {
        $comment = $this->stringOrNull($row['comment'] ?? null);

        return [
            'id' => $this->stringOrNull($row['.id'] ?? null),
            'title' => $title,
            'detail' => $detail,
            'comment' => $comment,
            'disabled' => $this->routerOsBoolean($row['disabled'] ?? false),
            'managed' => $comment !== null && str_starts_with($comment, self::NETWORK_MANAGED_COMMENT_PREFIX),
        ];
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private function networkDetail(array $parts): string
    {
        return implode(' · ', array_values(array_filter(
            $parts,
            fn (?string $part): bool => $part !== null && $part !== '',
        )));
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
            $this->recordFailure($router, 'GET', $endpoint, $started, $exception, $correlationId, $client);

            throw $this->asProbeException($exception, $client);
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
            $this->recordFailure(
                $router,
                'PUT',
                $endpoint,
                $started,
                $exception,
                $correlationId,
                $client,
                ['fields' => array_keys($payload)],
            );

            throw $this->asProbeException($exception, $client);
        }
    }

    private function delete(
        Client $client,
        Router $router,
        string $endpoint,
        string $correlationId,
    ): void {
        $started = hrtime(true);

        try {
            $client->delete($endpoint);

            $this->recorder->success(
                $router,
                'DELETE',
                $endpoint,
                $this->elapsedMs($started),
                200,
                [],
                [],
                $correlationId
            );
        } catch (Throwable $exception) {
            $this->recordFailure(
                $router,
                'DELETE',
                $endpoint,
                $started,
                $exception,
                $correlationId,
                $client,
            );

            throw $this->asProbeException($exception, $client);
        }
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function patch(
        Client $client,
        Router $router,
        string $endpoint,
        array $payload,
        string $profileName,
        string $correlationId,
    ): void {
        $started = hrtime(true);

        try {
            $client->patch($endpoint, $payload);

            $this->recorder->success(
                $router,
                'PATCH',
                $endpoint,
                $this->elapsedMs($started),
                200,
                ['profile' => $profileName, 'fields' => array_keys($payload)],
                [],
                $correlationId,
            );
        } catch (Throwable $exception) {
            $this->recordFailure(
                $router,
                'PATCH',
                $endpoint,
                $started,
                $exception,
                $correlationId,
                $client,
                ['profile' => $profileName, 'fields' => array_keys($payload)],
            );

            throw $this->asProbeException($exception, $client);
        }
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, string>  $desired
     */
    private function hotspotServerMatches(array $server, array $desired): bool
    {
        foreach ($desired as $key => $value) {
            if ($key === 'disabled') {
                if ($this->routerOsBoolean($server[$key] ?? false) !== $this->routerOsBoolean($value)) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($key, $server) || trim((string) $server[$key]) !== trim($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $servers
     * @param  array<string, string>  $desired
     * @return array<string, mixed>|null
     */
    private function findHotspotServerRow(array $servers, array $desired): ?array
    {
        foreach ($servers as $server) {
            if ($this->hotspotServerMatches($server, $desired)) {
                return $server;
            }
        }

        return null;
    }

    /** @return array{status: string, message: string} */
    private function check(bool $passed, string $message): array
    {
        return [
            'status' => $passed ? 'passed' : 'failed',
            'message' => $message,
        ];
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

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<string, mixed>|null
     */
    private function findHotspotUserProfileById(array $profiles, string $profileId, string $profileName): ?array
    {
        foreach ($profiles as $profile) {
            if (
                (($profile['.id'] ?? null) === $profileId)
                && (($profile['name'] ?? null) === $profileName)
            ) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @return array{upload: int, download: int}|null
     */
    private function hotspotProfileSpeeds(?string $rateLimit): ?array
    {
        try {
            return HotspotRateLimit::speedsKbps($rateLimit);
        } catch (InvalidArgumentException $exception) {
            throw RouterProbeException::configuration(
                'The Hotspot profile rate limit is invalid: '.$exception->getMessage()
            );
        }
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
        $hasDuration = $matched !== false && $matched > 0;

        if ($hasDuration) {
            foreach ($matches as $match) {
                $seconds += (int) $match[1] * match ($match[2]) {
                    'w' => 604800,
                    'd' => 86400,
                    'h' => 3600,
                    'm' => 60,
                    's' => 1,
                };
            }
        }

        if (preg_match('/(\d+):(\d{1,2}):(\d{1,2})\z/', $uptime, $clock) === 1) {
            $seconds += (int) $clock[1] * 3600 + (int) $clock[2] * 60 + (int) $clock[3];
            $hasDuration = true;
        }

        return $hasDuration ? $seconds : null;
    }
}
