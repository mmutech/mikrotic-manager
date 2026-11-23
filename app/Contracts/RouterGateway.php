<?php

namespace App\Contracts;

use App\Data\RouterProbeResult;
use App\Exceptions\RouterProbeException;
use App\Models\Router;

interface RouterGateway
{
    /**
     * Read identity and system resources. Read-only; never changes router state.
     *
     * @throws RouterProbeException
     */
    public function probe(Router $router, string $correlationId): RouterProbeResult;

    /**
     * Set the RouterOS system identity.
     *
     * @throws RouterProbeException
     */
    public function setIdentity(Router $router, string $identity, string $correlationId): void;

    /**
     * Read physical Ethernet interfaces and their current bridge membership.
     *
     * @return array<int, array{name: string, running: bool, disabled: bool, bridge: ?string}>
     *
     * @throws RouterProbeException
     */
    public function ethernetInterfaces(Router $router, string $correlationId): array;

    /**
     * Read enabled RFC 1918 IPv4 addresses assigned to router interfaces.
     *
     * @return array<int, array{ip: string, interface: ?string}>
     *
     * @throws RouterProbeException
     */
    public function privateIpv4Addresses(Router $router, string $correlationId): array;

    /**
     * Create a bridge if needed and add the selected Ethernet interfaces.
     *
     * @param  array<int, string>  $interfaces
     *
     * @throws RouterProbeException
     */
    public function configureBridge(
        Router $router,
        string $bridgeName,
        array $interfaces,
        string $correlationId,
    ): void;

    /**
     * Read the configured Hotspot server profiles.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RouterProbeException
     */
    public function hotspotProfiles(Router $router, string $correlationId): array;

    /**
     * Read router-level Hotspot user profiles.
     *
     * @return array<int, array{name: string, rate_limit: ?string, shared_users: string}>
     *
     * @throws RouterProbeException
     */
    public function hotspotUserProfiles(Router $router, string $correlationId): array;

    /**
     * Create a router-level Hotspot user profile without modifying existing profiles.
     *
     * @throws RouterProbeException
     */
    public function createHotspotUserProfile(
        Router $router,
        string $profileName,
        ?string $rateLimit,
        int $sharedUsers,
        string $correlationId,
    ): void;
}
