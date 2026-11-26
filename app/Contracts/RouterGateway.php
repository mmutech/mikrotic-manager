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
     * Read the current DHCP, NAT, and firewall configuration for review.
     *
     * @return array<string, array<int, array{id: ?string, title: string, detail: string, comment: ?string, disabled: bool, managed: bool}>>
     *
     * @throws RouterProbeException
     */
    public function networkConfiguration(Router $router, string $correlationId): array;

    /**
     * Read the MMUTech server's source network as seen by RouterOS.
     *
     * @return array<int, string>
     *
     * @throws RouterProbeException
     */
    public function managementSourceCidrs(Router $router, string $correlationId): array;

    /**
     * Create a bridge if needed, add selected Ethernet interfaces, and remove
     * selected interfaces from that bridge. Changes are verified by read-back.
     *
     * @param  array<int, string>  $interfaces
     * @param  array<int, string>  $interfacesToRemove
     *
     * @throws RouterProbeException
     */
    public function configureBridge(
        Router $router,
        string $bridgeName,
        array $interfaces,
        string $correlationId,
        array $interfacesToRemove = [],
    ): void;

    /**
     * Read all router interfaces so a Hotspot server can be bound to a bridge or VLAN.
     *
     * @return array<int, array{name: string, type: string, running: bool, disabled: bool}>
     *
     * @throws RouterProbeException
     */
    public function routerInterfaces(Router $router, string $correlationId): array;

    /**
     * Read IP pools that can be assigned to a Hotspot server.
     *
     * @return array<int, array{name: string, ranges: ?string}>
     *
     * @throws RouterProbeException
     */
    public function ipPools(Router $router, string $correlationId): array;

    /**
     * Read the configured Hotspot server profiles.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RouterProbeException
     */
    public function hotspotProfiles(Router $router, string $correlationId): array;

    /**
     * Read RouterOS Hotspot servers, including whether MMUTech manages them.
     *
     * @return array<int, array{id: ?string, name: string, interface: ?string, address_pool: ?string, profile: ?string, disabled: bool, managed: bool}>
     *
     * @throws RouterProbeException
     */
    public function hotspotServers(Router $router, string $correlationId): array;

    /**
     * Create or update the configured HotSpot server and verify it.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, array{status: string, message: string}>
     *
     * @throws RouterProbeException
     */
    public function configureHotspotServer(Router $router, array $configuration, string $correlationId): array;

    /**
     * Enable cookie-based login on RouterOS Hotspot server profiles.
     *
     * @throws RouterProbeException
     */
    public function enableHotspotCookieLogin(Router $router, string $correlationId): void;

    /**
     * Read router-level Hotspot user profiles.
     *
     * @return array<int, array{id: ?string, name: string, rate_limit: ?string, shared_users: string}>
     *
     * @throws RouterProbeException
     */
    public function hotspotUserProfiles(Router $router, string $correlationId): array;

    /**
     * Read active HotSpot sessions. Read-only; never changes router state.
     *
     * @return array<int, array{id: ?string, username: string, ip_address: ?string, mac_address: ?string, connected_seconds: ?int, rx_bytes: ?int, tx_bytes: ?int, total_bytes: int}>
     *
     * @throws RouterProbeException
     */
    public function activeHotspotSessions(Router $router, string $correlationId): array;

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

    /**
     * Update an existing router-level Hotspot user profile after checking that
     * its discovered settings are still current, then verify the RouterOS
     * read-back. Only rate-limit and shared-users are changed.
     *
     * @throws RouterProbeException
     */
    public function updateHotspotUserProfile(
        Router $router,
        string $profileId,
        string $profileName,
        ?string $expectedRateLimit,
        int $expectedSharedUsers,
        ?string $rateLimit,
        int $sharedUsers,
        string $correlationId,
    ): void;

    /**
     * Export RouterOS configuration to a temporary .rsc file, read it back in
     * chunks, and remove the temporary router file.
     *
     * @return array{contents: string, checks: array<string, array{status: string, message: string}>, file_name: string}
     *
     * @throws RouterProbeException
     */
    public function exportConfiguration(Router $router, string $fileName, string $correlationId): array;

    /**
     * Upload a .rsc file to the router and read it back for verification.
     *
     * @throws RouterProbeException
     */
    public function uploadConfigurationFile(
        Router $router,
        string $fileName,
        string $contents,
        string $correlationId,
    ): void;

    /**
     * Run an uploaded .rsc file through RouterOS import. Dry runs validate
     * syntax without changing configuration.
     *
     * @return array{messages: array<int, string>, checks: array<string, array{status: string, message: string}>}
     *
     * @throws RouterProbeException
     */
    public function importConfigurationFile(
        Router $router,
        string $fileName,
        bool $dryRun,
        string $correlationId,
    ): array;

    /**
     * Remove an uploaded configuration file from the router.
     *
     * @throws RouterProbeException
     */
    public function deleteConfigurationFile(Router $router, string $fileName, string $correlationId): void;

    /**
     * Remove MMUTech-managed configuration while preserving management access
     * and voucher accounts, then verify the resulting RouterOS state.
     *
     * @param  array<int, string>  $managedInterfaces
     * @param  array<int, string>  $managedProfileNames
     * @return array<string, array{status: string, message: string}>
     *
     * @throws RouterProbeException
     */
    public function resetManagedConfiguration(
        Router $router,
        string $expectedIdentity,
        string $bridgeName,
        array $managedInterfaces,
        array $managedProfileNames,
        string $correlationId,
    ): array;
}
