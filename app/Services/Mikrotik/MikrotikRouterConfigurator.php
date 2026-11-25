<?php

namespace App\Services\Mikrotik;

use App\Enums\RouterErrorCategory;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use App\Support\Ipv4Cidr;
use App\Support\RouterIdentity;
use Mivo\MikrotikRos7\Client;
use Mivo\MikrotikRos7\Exceptions\MikrotikException;
use Throwable;

class MikrotikRouterConfigurator
{
    private const ADDRESS_COMMENT = 'MMUTech managed LAN address';

    private const POOL_COMMENT = 'MMUTech managed LAN DHCP pool';

    private const DHCP_SERVER_COMMENT = 'MMUTech managed LAN DHCP server';

    private const DHCP_NETWORK_COMMENT = 'MMUTech managed LAN DHCP network';

    private const NAT_COMMENT = 'MMUTech managed LAN masquerade';

    private const FIREWALL_COMMENT_PREFIX = 'MMUTech managed firewall ';

    public function __construct(
        private readonly RouterConnectionFactory $connections,
        private readonly RouterApiRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function normalize(array $configuration): array
    {
        $bridgeName = $configuration['bridge_name'] ?? null;
        $lanAddress = $configuration['lan_address'] ?? null;
        $poolStart = $configuration['pool_start'] ?? null;
        $poolEnd = $configuration['pool_end'] ?? null;
        $wanInterface = $configuration['wan_interface'] ?? null;
        $dnsServers = $configuration['dns_servers'] ?? null;
        $managementCidrs = $configuration['management_cidrs'] ?? null;

        if (! is_string($bridgeName) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $bridgeName) !== 1) {
            throw RouterProbeException::configuration('Enter a valid bridge name.');
        }

        if (! is_string($lanAddress)) {
            throw RouterProbeException::configuration('Enter the LAN bridge IPv4 address and prefix.');
        }

        $lan = $this->parseIpv4Cidr($lanAddress, 1, 30);

        if ($lan['address_long'] <= $lan['network_long'] || $lan['address_long'] >= $lan['broadcast_long']) {
            throw RouterProbeException::configuration('The LAN gateway address must be a usable host address inside its subnet.');
        }

        if (! is_string($poolStart) || ! is_string($poolEnd)) {
            throw RouterProbeException::configuration('Enter both ends of the DHCP address pool.');
        }

        $poolStartLong = $this->parseIpv4Address($poolStart);
        $poolEndLong = $this->parseIpv4Address($poolEnd);

        if (
            $poolStartLong < $lan['network_long']
            || $poolEndLong > $lan['broadcast_long']
            || $poolStartLong <= $lan['network_long']
            || $poolEndLong >= $lan['broadcast_long']
            || $poolStartLong > $poolEndLong
            || ($poolStartLong <= $lan['address_long'] && $poolEndLong >= $lan['address_long'])
        ) {
            throw RouterProbeException::configuration('The DHCP pool must be an ascending range of host addresses inside the LAN subnet and must not include the router address.');
        }

        if (
            ! is_string($wanInterface)
            || trim($wanInterface) === ''
            || preg_match('/[\x00-\x1F\x7F]/', $wanInterface) === 1
        ) {
            throw RouterProbeException::configuration('Select a valid Ethernet interface for the WAN connection.');
        }

        if (! is_array($dnsServers) || $dnsServers === []) {
            throw RouterProbeException::configuration('Enter at least one IPv4 DNS server.');
        }

        $dnsServers = array_values(array_unique(array_map(function (mixed $server): string {
            if (! is_string($server)) {
                throw RouterProbeException::configuration('Every DNS server must be an IPv4 address.');
            }

            $server = trim($server);
            $this->parseIpv4Address($server);

            return $server;
        }, $dnsServers)));

        if (in_array($lan['address'], $dnsServers, true)) {
            throw RouterProbeException::configuration('Use upstream DNS server addresses; RouterOS DNS forwarding is not enabled by this configuration.');
        }

        if (! is_array($managementCidrs) || $managementCidrs === []) {
            throw RouterProbeException::configuration('Enter at least one management source CIDR to keep RouterOS API access available.');
        }

        $managementCidrs = array_values(array_unique(array_map(function (mixed $cidr): string {
            if (! is_string($cidr)) {
                throw RouterProbeException::configuration('Every management source must be a valid IPv4 CIDR.');
            }

            $normalized = Ipv4Cidr::normalize($cidr);

            if ($normalized === null) {
                throw RouterProbeException::configuration('Management CIDRs must be valid IPv4 networks with prefixes from /8 through /32.');
            }

            return $normalized;
        }, $managementCidrs)));

        return [
            'bridge_name' => $bridgeName,
            'lan_address' => $lan['address'].'/'.$lan['prefix'],
            'lan_gateway' => $lan['address'],
            'network_cidr' => $lan['network'].'/'.$lan['prefix'],
            'pool_start' => long2ip($poolStartLong),
            'pool_end' => long2ip($poolEndLong),
            'pool_range' => long2ip($poolStartLong).'-'.long2ip($poolEndLong),
            'wan_interface' => trim($wanInterface),
            'dns_servers' => $dnsServers,
            'management_cidrs' => $managementCidrs,
            'management_impact_confirmed' => ($configuration['management_impact_confirmed'] ?? false) === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, string>
     */
    public function terminalCommands(Router $router, array $configuration): array
    {
        $configuration = $this->normalize($configuration);
        $poolName = $this->lanPoolName($router);

        if (! $configuration['management_impact_confirmed']) {
            throw RouterProbeException::configuration('Confirm the network-impact warning before generating terminal commands.');
        }

        $commands = [
            $this->terminalCommand('/ip address add', [
                'address' => $configuration['lan_address'],
                'interface' => $configuration['bridge_name'],
                'disabled' => 'no',
                'comment' => self::ADDRESS_COMMENT,
            ]),
            $this->terminalCommand('/ip pool add', [
                'name' => $poolName,
                'ranges' => $configuration['pool_range'],
                'comment' => self::POOL_COMMENT,
            ]),
            $this->terminalCommand('/ip dhcp-server network add', [
                'address' => $configuration['network_cidr'],
                'gateway' => $configuration['lan_gateway'],
                'dns-server' => implode(',', $configuration['dns_servers']),
                'comment' => self::DHCP_NETWORK_COMMENT,
            ]),
            $this->terminalCommand('/ip dhcp-server add', [
                'name' => 'mmutech-lan-dhcp',
                'interface' => $configuration['bridge_name'],
                'address-pool' => $poolName,
                'lease-time' => '1d',
                'disabled' => 'no',
                'comment' => self::DHCP_SERVER_COMMENT,
            ]),
            $this->terminalCommand('/ip firewall nat add', [
                'chain' => 'srcnat',
                'action' => 'masquerade',
                'src-address' => $configuration['network_cidr'],
                'out-interface' => $configuration['wan_interface'],
                'disabled' => 'no',
                'comment' => self::NAT_COMMENT,
            ]),
        ];

        foreach (array_reverse($this->firewallRules($router, $configuration)) as $rule) {
            $commands[] = $this->terminalCommand(
                '/ip firewall filter add',
                array_merge($rule['settings'], [
                    'comment' => $rule['comment'],
                    'place-before' => '0',
                ]),
            );
        }

        return [
            ...$commands,
            '/ip address print detail',
            '/ip pool print detail',
            '/ip dhcp-server print detail',
            '/ip dhcp-server network print detail',
            '/ip firewall nat print detail',
            '/ip firewall filter print detail',
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array{status: string, message: string}>
     */
    public function configure(Router $router, array $configuration, string $correlationId): array
    {
        $configuration = $this->normalize($configuration);
        $poolName = $this->lanPoolName($router);

        if (! $configuration['management_impact_confirmed']) {
            throw RouterProbeException::configuration('Confirm the network-impact warning before applying this configuration.');
        }

        $client = $this->connections->make($router);
        $this->assertBridgeAndWan($client, $router, $configuration, $correlationId);

        $addresses = $this->getRows($client, $router, '/rest/ip/address', $correlationId);
        $this->assertLanAddressDoesNotOverlap($addresses, $configuration);

        $dhcpServers = $this->getRows($client, $router, '/rest/ip/dhcp-server', $correlationId);
        $this->assertNoOtherDhcpServerOnBridge($dhcpServers, $configuration);

        $this->assertResourceCanBeEnsured(
            $client,
            $router,
            '/rest/ip/address',
            ['address' => $configuration['lan_address'], 'interface' => $configuration['bridge_name']],
            self::ADDRESS_COMMENT,
            [
                'address' => $configuration['lan_address'],
                'interface' => $configuration['bridge_name'],
                'disabled' => 'no',
            ],
            $correlationId,
        );
        $this->assertResourceCanBeEnsured(
            $client,
            $router,
            '/rest/ip/pool',
            ['name' => $poolName],
            self::POOL_COMMENT,
            ['ranges' => $configuration['pool_range']],
            $correlationId,
        );
        $this->assertResourceCanBeEnsured(
            $client,
            $router,
            '/rest/ip/dhcp-server',
            ['name' => 'mmutech-lan-dhcp'],
            self::DHCP_SERVER_COMMENT,
            [
                'interface' => $configuration['bridge_name'],
                'address-pool' => $poolName,
                'disabled' => 'no',
                'lease-time' => '1d',
            ],
            $correlationId,
        );
        $this->assertResourceCanBeEnsured(
            $client,
            $router,
            '/rest/ip/dhcp-server/network',
            ['address' => $configuration['network_cidr']],
            self::DHCP_NETWORK_COMMENT,
            [
                'gateway' => $configuration['lan_gateway'],
                'dns-server' => implode(',', $configuration['dns_servers']),
            ],
            $correlationId,
        );
        $this->assertResourceCanBeEnsured(
            $client,
            $router,
            '/rest/ip/firewall/nat',
            [
                'chain' => 'srcnat',
                'action' => 'masquerade',
                'src-address' => $configuration['network_cidr'],
                'out-interface' => $configuration['wan_interface'],
            ],
            self::NAT_COMMENT,
            ['disabled' => 'no'],
            $correlationId,
        );
        $this->assertFirewallRulesCanBeEnsured($client, $router, $configuration, $correlationId);

        $this->ensureResource(
            $client,
            $router,
            '/rest/ip/address',
            ['address' => $configuration['lan_address'], 'interface' => $configuration['bridge_name']],
            self::ADDRESS_COMMENT,
            [
                'address' => $configuration['lan_address'],
                'interface' => $configuration['bridge_name'],
                'disabled' => 'no',
            ],
            $correlationId,
        );

        $this->ensureResource(
            $client,
            $router,
            '/rest/ip/pool',
            ['name' => $poolName],
            self::POOL_COMMENT,
            ['ranges' => $configuration['pool_range']],
            $correlationId,
        );

        $this->ensureResource(
            $client,
            $router,
            '/rest/ip/dhcp-server',
            ['name' => 'mmutech-lan-dhcp'],
            self::DHCP_SERVER_COMMENT,
            [
                'interface' => $configuration['bridge_name'],
                'address-pool' => $poolName,
                'disabled' => 'no',
                'lease-time' => '1d',
            ],
            $correlationId,
        );

        $this->ensureResource(
            $client,
            $router,
            '/rest/ip/dhcp-server/network',
            ['address' => $configuration['network_cidr']],
            self::DHCP_NETWORK_COMMENT,
            [
                'gateway' => $configuration['lan_gateway'],
                'dns-server' => implode(',', $configuration['dns_servers']),
            ],
            $correlationId,
        );

        $this->ensureResource(
            $client,
            $router,
            '/rest/ip/firewall/nat',
            [
                'chain' => 'srcnat',
                'action' => 'masquerade',
                'src-address' => $configuration['network_cidr'],
                'out-interface' => $configuration['wan_interface'],
            ],
            self::NAT_COMMENT,
            ['disabled' => 'no'],
            $correlationId,
        );

        $this->ensureFirewallRules($client, $router, $configuration, $correlationId);

        return $this->verifyWithClient($client, $router, $configuration, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array{status: string, message: string}>
     */
    public function verify(Router $router, array $configuration, string $correlationId): array
    {
        $configuration = $this->normalize($configuration);
        $client = $this->connections->make($router);

        return $this->verifyWithClient($client, $router, $configuration, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function assertBridgeAndWan(
        Client $client,
        Router $router,
        array $configuration,
        string $correlationId,
    ): void {
        $interfaces = $this->getRows($client, $router, '/rest/interface', $correlationId);
        $bridgeExists = false;
        $interfaceTypes = [];

        foreach ($interfaces as $interface) {
            $name = $this->stringOrNull($interface['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $interfaceTypes[$name] = strtolower((string) ($interface['type'] ?? ''));

            if ($name === $configuration['bridge_name'] && $interfaceTypes[$name] === 'bridge') {
                $bridgeExists = true;
            }
        }

        if (! $bridgeExists) {
            throw RouterProbeException::configuration('Configure the LAN bridge before setting up DHCP, NAT, and firewall rules.');
        }

        if (! in_array($interfaceTypes[$configuration['wan_interface']] ?? null, ['ether', 'ethernet'], true)) {
            throw RouterProbeException::configuration('The selected WAN interface is no longer an available Ethernet interface.');
        }

        $bridgePorts = $this->getRows($client, $router, '/rest/interface/bridge/port', $correlationId);
        $lanHasPorts = false;

        foreach ($bridgePorts as $bridgePort) {
            $member = $this->stringOrNull($bridgePort['interface'] ?? null);
            $bridge = $this->stringOrNull($bridgePort['bridge'] ?? null);

            if ($bridge === $configuration['bridge_name']) {
                $lanHasPorts = true;
            }

            if ($member === $configuration['wan_interface']) {
                throw RouterProbeException::configuration('The selected WAN interface already belongs to a bridge and cannot be used as a separate WAN port.');
            }
        }

        if (! $lanHasPorts) {
            throw RouterProbeException::configuration('Add LAN interfaces to the selected bridge before configuring DHCP and Internet sharing.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $addresses
     * @param  array<string, mixed>  $configuration
     */
    private function assertLanAddressDoesNotOverlap(array $addresses, array $configuration): void
    {
        $lan = $this->parseIpv4Cidr($configuration['lan_address'], 1, 30);

        foreach ($addresses as $address) {
            if ($this->routerOsBoolean($address['disabled'] ?? false)) {
                continue;
            }

            $existingAddress = $this->stringOrNull($address['address'] ?? null);

            if ($existingAddress === null) {
                continue;
            }

            try {
                $existing = $this->parseIpv4Cidr(
                    str_contains($existingAddress, '/') ? $existingAddress : $existingAddress.'/32',
                    0,
                    32
                );
            } catch (RouterProbeException) {
                continue;
            }

            $overlaps = $lan['network_long'] <= $existing['broadcast_long']
                && $existing['network_long'] <= $lan['broadcast_long'];

            if (! $overlaps) {
                continue;
            }

            if (
                ($address['comment'] ?? null) === self::ADDRESS_COMMENT
                || (
                    ($address['interface'] ?? null) === $configuration['bridge_name']
                    && $existingAddress === $configuration['lan_address']
                )
            ) {
                continue;
            }

            throw RouterProbeException::configuration('The requested LAN subnet overlaps an existing RouterOS IP address.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $dhcpServers
     * @param  array<string, mixed>  $configuration
     */
    private function assertNoOtherDhcpServerOnBridge(array $dhcpServers, array $configuration): void
    {
        foreach ($dhcpServers as $server) {
            if (($server['interface'] ?? null) !== $configuration['bridge_name']) {
                continue;
            }

            if (
                ($server['name'] ?? null) === 'mmutech-lan-dhcp'
                && ($server['comment'] ?? null) === self::DHCP_SERVER_COMMENT
            ) {
                continue;
            }

            throw RouterProbeException::configuration('A DHCP server already exists on this bridge. Review it before adding another server.');
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function ensureFirewallRules(
        Client $client,
        Router $router,
        array $configuration,
        string $correlationId,
    ): void {
        $rules = $this->firewallRules($router, $configuration);
        $existingRules = $this->getRows($client, $router, '/rest/ip/firewall/filter', $correlationId);
        $wantedComments = array_column($rules, 'comment');

        $accessRules = array_values(array_filter($rules, fn (array $rule): bool => $this->isFirewallAccessRule($rule)));
        $remainingRules = array_values(array_filter($rules, fn (array $rule): bool => ! $this->isFirewallAccessRule($rule)));
        $anchor = $this->firstNonAccessFirewallRuleId($existingRules);

        foreach (array_reverse($accessRules) as $rule) {
            $anchor = $this->ensureFirewallRule($client, $router, $existingRules, $rule, $anchor, $correlationId);
        }

        $existingRules = $this->getRows($client, $router, '/rest/ip/firewall/filter', $correlationId);

        foreach ($existingRules as $existing) {
            $comment = (string) ($existing['comment'] ?? '');

            if (
                str_starts_with($comment, self::FIREWALL_COMMENT_PREFIX.'input management ')
                && ! in_array($comment, $wantedComments, true)
                && ! $this->routerOsBoolean($existing['disabled'] ?? false)
            ) {
                $id = $this->stringOrNull($existing['.id'] ?? null);

                if ($id === null) {
                    throw RouterProbeException::configuration('An obsolete managed management rule cannot be disabled because it has no identifier.');
                }

                $this->patchResource(
                    $client,
                    $router,
                    '/rest/ip/firewall/filter/'.$id,
                    ['disabled' => 'yes'],
                    $correlationId,
                );
            }
        }

        $anchor = $this->firstNonAccessFirewallRuleId($existingRules);

        if ($anchor === null) {
            foreach ($remainingRules as $rule) {
                $this->ensureFirewallRule($client, $router, $existingRules, $rule, null, $correlationId);
            }

            return;
        }

        foreach (array_reverse($remainingRules) as $rule) {
            $anchor = $this->ensureFirewallRule($client, $router, $existingRules, $rule, $anchor, $correlationId);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $existingRules
     * @param  array{comment: string, settings: array<string, string>}  $rule
     */
    private function ensureFirewallRule(
        Client $client,
        Router $router,
        array $existingRules,
        array $rule,
        ?string $anchor,
        string $correlationId,
    ): ?string {
        $matching = array_values(array_filter(
            $existingRules,
            fn (array $existing): bool => ($existing['comment'] ?? null) === $rule['comment']
        ));

        if (count($matching) > 1) {
            throw RouterProbeException::configuration('Duplicate MMUTech firewall rules were found. Review the firewall before retrying.');
        }

        if ($matching !== []) {
            $this->updateManagedFirewallRule($client, $router, $matching[0], $rule, $correlationId);

            return $this->stringOrNull($matching[0]['.id'] ?? null) ?? $anchor;
        }

        $payload = array_merge($rule['settings'], ['comment' => $rule['comment']]);

        if ($anchor !== null) {
            $payload['place-before'] = $anchor;
        }

        $created = $this->createResource(
            $client,
            $router,
            '/rest/ip/firewall/filter',
            $payload,
            $correlationId,
        );
        $createdId = $this->stringOrNull($created['.id'] ?? null);

        if ($createdId === null) {
            $currentRules = $this->getRows($client, $router, '/rest/ip/firewall/filter', $correlationId);
            $createdId = $this->findRowIdByComment($currentRules, $rule['comment']);
        }

        if ($createdId === null) {
            throw new RouterProbeException('RouterOS did not return the new firewall rule identifier.', RouterErrorCategory::InvalidResponse);
        }

        return $createdId;
    }

    private function lanPoolName(Router $router): string
    {
        return RouterIdentity::fromRouter($router);
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function terminalCommand(string $path, array $settings): string
    {
        $arguments = [];

        foreach ($settings as $key => $value) {
            $arguments[] = $key.'='.$this->terminalValue($value);
        }

        return $path.' '.implode(' ', $arguments);
    }

    private function terminalValue(string $value): string
    {
        if (preg_match('~\A[A-Za-z0-9._:/,!*-]+\z~D', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @param  array<string, string>  $rule
     */
    private function isFirewallAccessRule(array $rule): bool
    {
        return $rule['comment'] === self::FIREWALL_COMMENT_PREFIX.'input established'
            || str_starts_with($rule['comment'], self::FIREWALL_COMMENT_PREFIX.'input management ');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function firstNonAccessFirewallRuleId(array $rules): ?string
    {
        foreach ($rules as $rule) {
            $comment = (string) ($rule['comment'] ?? '');
            $isAccessRule = $comment === self::FIREWALL_COMMENT_PREFIX.'input established'
                || str_starts_with($comment, self::FIREWALL_COMMENT_PREFIX.'input management ');

            if ($isAccessRule) {
                continue;
            }

            $id = $this->stringOrNull($rule['.id'] ?? null);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $settings
     */
    private function assertResourceCanBeEnsured(
        Client $client,
        Router $router,
        string $endpoint,
        array $identity,
        string $comment,
        array $settings,
        string $correlationId,
    ): void {
        $rows = $this->getRows($client, $router, $endpoint, $correlationId);
        $managedRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['comment'] ?? null) === $comment
        ));

        if (count($managedRows) > 1) {
            throw RouterProbeException::configuration('Duplicate MMUTech-managed RouterOS entries were found. Review the configuration before retrying.');
        }

        if ($managedRows !== []) {
            if (
                ! $this->matches($managedRows[0], array_merge($identity, $settings, ['comment' => $comment]))
                && $this->stringOrNull($managedRows[0]['.id'] ?? null) === null
            ) {
                throw RouterProbeException::configuration('A managed RouterOS entry cannot be updated because it has no identifier.');
            }

            return;
        }

        $matchingRows = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row, $identity)));

        if ($matchingRows !== [] && (count($matchingRows) !== 1 || ! $this->matches($matchingRows[0], array_merge($identity, $settings)))) {
            throw RouterProbeException::configuration('A conflicting RouterOS entry already uses the requested configuration name or address.');
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function assertFirewallRulesCanBeEnsured(
        Client $client,
        Router $router,
        array $configuration,
        string $correlationId,
    ): void {
        $existingRules = $this->getRows($client, $router, '/rest/ip/firewall/filter', $correlationId);
        $rules = $this->firewallRules($router, $configuration);
        $wantedComments = array_column($rules, 'comment');

        foreach ($rules as $rule) {
            $matching = array_values(array_filter(
                $existingRules,
                fn (array $existing): bool => ($existing['comment'] ?? null) === $rule['comment']
            ));

            if (count($matching) > 1) {
                throw RouterProbeException::configuration('Duplicate MMUTech firewall rules were found. Review the firewall before retrying.');
            }

            if (
                $matching !== []
                && ! $this->matches($matching[0], array_merge($rule['settings'], ['comment' => $rule['comment']]))
                && $this->stringOrNull($matching[0]['.id'] ?? null) === null
            ) {
                throw RouterProbeException::configuration('A managed firewall rule cannot be updated because RouterOS returned no rule identifier.');
            }
        }

        foreach ($existingRules as $existing) {
            $comment = (string) ($existing['comment'] ?? '');

            if (
                str_starts_with($comment, self::FIREWALL_COMMENT_PREFIX.'input management ')
                && ! in_array($comment, $wantedComments, true)
                && ! $this->routerOsBoolean($existing['disabled'] ?? false)
                && $this->stringOrNull($existing['.id'] ?? null) === null
            ) {
                throw RouterProbeException::configuration('An obsolete managed management rule cannot be disabled because it has no identifier.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array{comment: string, settings: array<string, string>}  $rule
     */
    private function updateManagedFirewallRule(
        Client $client,
        Router $router,
        array $existing,
        array $rule,
        string $correlationId,
    ): void {
        $desired = array_merge($rule['settings'], ['comment' => $rule['comment']]);

        if ($this->matches($existing, $desired)) {
            return;
        }

        $id = $this->stringOrNull($existing['.id'] ?? null);

        if ($id === null) {
            throw RouterProbeException::configuration('A managed firewall rule cannot be updated because RouterOS returned no rule identifier.');
        }

        $this->patchResource($client, $router, '/rest/ip/firewall/filter/'.$id, $desired, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array{status: string, message: string}>
     */
    private function verifyWithClient(
        Client $client,
        Router $router,
        array $configuration,
        string $correlationId,
    ): array {
        $checks = [];
        $poolName = $this->lanPoolName($router);
        $interfaces = $this->getRows($client, $router, '/rest/interface', $correlationId);
        $bridgeExists = collect($interfaces)->contains(
            fn (array $interface): bool => ($interface['name'] ?? null) === $configuration['bridge_name']
                && strtolower((string) ($interface['type'] ?? '')) === 'bridge'
        );
        $bridgePorts = $this->getRows($client, $router, '/rest/interface/bridge/port', $correlationId);
        $hasLanPort = collect($bridgePorts)->contains(
            fn (array $port): bool => ($port['bridge'] ?? null) === $configuration['bridge_name']
        );
        $checks['bridge'] = $this->check(
            $bridgeExists && $hasLanPort,
            $bridgeExists && $hasLanPort
                ? 'The LAN bridge exists and has at least one member interface.'
                : 'The LAN bridge or its member interfaces are missing.'
        );

        $addresses = $this->getRows($client, $router, '/rest/ip/address', $correlationId);
        $checks['lan_address'] = $this->check(
            $this->containsMatching($addresses, [
                'address' => $configuration['lan_address'],
                'interface' => $configuration['bridge_name'],
                'disabled' => 'no',
            ]),
            'RouterOS LAN address and bridge assignment were read back.'
        );
        $hasWanAddress = $this->containsMatching($addresses, [
            'interface' => $configuration['wan_interface'],
            'disabled' => 'no',
        ]);
        $checks['wan_address'] = [
            'status' => $hasWanAddress ? 'passed' : 'warning',
            'message' => $hasWanAddress
                ? 'The WAN interface has an enabled IPv4 address.'
                : 'No enabled IPv4 address was found on the WAN interface; Internet sharing may need upstream addressing.',
        ];

        $routes = $this->getRows($client, $router, '/rest/ip/route', $correlationId);
        $hasDefaultRoute = collect($routes)->contains(
            fn (array $route): bool => ($route['dst-address'] ?? null) === '0.0.0.0/0'
                && $this->routerOsBoolean($route['active'] ?? false)
                && ! $this->routerOsBoolean($route['disabled'] ?? false)
        );
        $checks['default_route'] = [
            'status' => $hasDefaultRoute ? 'passed' : 'warning',
            'message' => $hasDefaultRoute
                ? 'An active IPv4 default route is configured.'
                : 'No active IPv4 default route was found; Internet sharing may need upstream routing.',
        ];

        $pools = $this->getRows($client, $router, '/rest/ip/pool', $correlationId);
        $checks['dhcp_pool'] = $this->check(
            $this->containsMatching($pools, ['name' => $poolName, 'ranges' => $configuration['pool_range']]),
            'RouterOS DHCP pool range was read back.'
        );

        $dhcpServers = $this->getRows($client, $router, '/rest/ip/dhcp-server', $correlationId);
        $checks['dhcp_server'] = $this->check(
            $this->containsMatching($dhcpServers, [
                'name' => 'mmutech-lan-dhcp',
                'interface' => $configuration['bridge_name'],
                'address-pool' => $poolName,
                'disabled' => 'no',
            ]),
            'RouterOS DHCP server is enabled on the LAN bridge.'
        );

        $dhcpNetworks = $this->getRows($client, $router, '/rest/ip/dhcp-server/network', $correlationId);
        $checks['dhcp_network'] = $this->check(
            $this->containsMatching($dhcpNetworks, [
                'address' => $configuration['network_cidr'],
                'gateway' => $configuration['lan_gateway'],
                'dns-server' => implode(',', $configuration['dns_servers']),
            ]),
            'RouterOS DHCP network, gateway, and DNS options were read back.'
        );

        $natRules = $this->getRows($client, $router, '/rest/ip/firewall/nat', $correlationId);
        $checks['nat'] = $this->check(
            $this->containsMatching($natRules, [
                'chain' => 'srcnat',
                'action' => 'masquerade',
                'src-address' => $configuration['network_cidr'],
                'out-interface' => $configuration['wan_interface'],
                'disabled' => 'no',
            ]),
            'The LAN masquerade rule was read back.'
        );

        $firewallRules = $this->getRows($client, $router, '/rest/ip/firewall/filter', $correlationId);
        $expectedFirewallRules = $this->firewallRules($router, $configuration);
        $rulePositions = [];

        foreach ($expectedFirewallRules as $rule) {
            $position = $this->findMatchingRulePosition($firewallRules, $rule);

            if ($position === null) {
                $rulePositions = [];

                break;
            }

            $rulePositions[] = $position;
        }

        $ordered = $rulePositions !== [] && $rulePositions === array_values(array_unique($rulePositions));
        if ($ordered) {
            $sortedPositions = $rulePositions;
            sort($sortedPositions);
            $ordered = $sortedPositions === $rulePositions;
        }

        $checks['firewall'] = $this->check(
            $ordered,
            $ordered
                ? 'All managed firewall rules are present and ordered.'
                : 'One or more firewall rules are missing, mismatched, or out of order.'
        );

        $firstManagedPosition = $rulePositions === [] ? null : min($rulePositions);
        $hasPrecedingRules = $firstManagedPosition !== null
            && collect(array_slice($firewallRules, 0, $firstManagedPosition))
                ->contains(fn (array $rule): bool => ! str_starts_with((string) ($rule['comment'] ?? ''), self::FIREWALL_COMMENT_PREFIX));
        $checks['firewall_precedence'] = [
            'status' => $hasPrecedingRules ? 'warning' : 'passed',
            'message' => $hasPrecedingRules
                ? 'Existing firewall rules precede the MMUTech policy and may change its effect.'
                : 'The MMUTech policy precedes existing firewall rules.',
        ];
        $hasShadowedWanAccepts = collect($firewallRules)->contains(
            function (array $rule) use ($configuration): bool {
                if (
                    str_starts_with((string) ($rule['comment'] ?? ''), self::FIREWALL_COMMENT_PREFIX)
                    || ($rule['action'] ?? null) !== 'accept'
                    || in_array($rule['connection-state'] ?? null, ['established', 'established,related', 'established,related,untracked'], true)
                ) {
                    return false;
                }

                $chain = $rule['chain'] ?? null;

                if ($chain === 'input') {
                    return ! isset($rule['in-interface-list'])
                        && (! isset($rule['in-interface']) || $rule['in-interface'] === $configuration['wan_interface']);
                }

                return $chain === 'forward'
                    && (! isset($rule['in-interface-list'])
                        && (! isset($rule['in-interface']) || $rule['in-interface'] === $configuration['wan_interface']))
                    && ($rule['connection-nat-state'] ?? null) !== 'dstnat';
            }
        );
        $checks['firewall_conflicts'] = [
            'status' => $hasShadowedWanAccepts ? 'warning' : 'passed',
            'message' => $hasShadowedWanAccepts
                ? 'Existing WAN ACCEPT rules may be shadowed by the managed drops; review inbound services and VPNs.'
                : 'No conflicting existing WAN ACCEPT rules were detected.',
        ];

        $natPosition = $this->findMatchingRulePosition($natRules, [
            'chain' => 'srcnat',
            'action' => 'masquerade',
            'src-address' => $configuration['network_cidr'],
            'out-interface' => $configuration['wan_interface'],
            'disabled' => 'no',
        ]);
        $checks['nat_precedence'] = [
            'status' => $natPosition !== null && $natPosition > 0 ? 'warning' : 'passed',
            'message' => $natPosition !== null && $natPosition > 0
                ? 'Other NAT rules precede the MMUTech masquerade rule.'
                : 'The MMUTech masquerade rule is first or is the only matching rule.',
        ];

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, array{comment: string, settings: array<string, string>}>
     */
    private function firewallRules(Router $router, array $configuration): array
    {
        $rules = [
            [
                'comment' => self::FIREWALL_COMMENT_PREFIX.'input established',
                'settings' => ['chain' => 'input', 'connection-state' => 'established,related,untracked', 'action' => 'accept', 'disabled' => 'no'],
            ],
        ];

        foreach ($configuration['management_cidrs'] as $index => $cidr) {
            $rules[] = [
                'comment' => self::FIREWALL_COMMENT_PREFIX.'input management '.($index + 1),
                'settings' => [
                    'chain' => 'input',
                    'src-address' => $cidr,
                    'protocol' => 'tcp',
                    'dst-port' => (string) $router->rest_port,
                    'action' => 'accept',
                    'disabled' => 'no',
                ],
            ];
        }

        $rules[] = [
            'comment' => self::FIREWALL_COMMENT_PREFIX.'input invalid',
            'settings' => ['chain' => 'input', 'connection-state' => 'invalid', 'action' => 'drop', 'disabled' => 'no'],
        ];
        $rules[] = [
            'comment' => self::FIREWALL_COMMENT_PREFIX.'input DHCP from LAN',
            'settings' => [
                'chain' => 'input',
                'in-interface' => $configuration['bridge_name'],
                'protocol' => 'udp',
                'dst-port' => '67',
                'action' => 'accept',
                'disabled' => 'no',
            ],
        ];

        $rules[] = [
            'comment' => self::FIREWALL_COMMENT_PREFIX.'input drop WAN',
            'settings' => ['chain' => 'input', 'in-interface' => $configuration['wan_interface'], 'action' => 'drop', 'disabled' => 'no'],
        ];
        $rules[] = [
            'comment' => self::FIREWALL_COMMENT_PREFIX.'forward established',
            'settings' => ['chain' => 'forward', 'connection-state' => 'established,related,untracked', 'action' => 'accept', 'disabled' => 'no'],
        ];
        $rules[] = [
            'comment' => self::FIREWALL_COMMENT_PREFIX.'forward invalid',
            'settings' => ['chain' => 'forward', 'connection-state' => 'invalid', 'action' => 'drop', 'disabled' => 'no'],
        ];
        $rules[] = [
            'comment' => self::FIREWALL_COMMENT_PREFIX.'forward LAN to WAN',
            'settings' => [
                'chain' => 'forward',
                'in-interface' => $configuration['bridge_name'],
                'out-interface' => $configuration['wan_interface'],
                'src-address' => $configuration['network_cidr'],
                'action' => 'accept',
                'disabled' => 'no',
            ],
        ];
        $rules[] = [
            'comment' => self::FIREWALL_COMMENT_PREFIX.'forward drop WAN',
            'settings' => [
                'chain' => 'forward',
                'in-interface' => $configuration['wan_interface'],
                'connection-state' => 'new',
                'connection-nat-state' => '!dstnat',
                'action' => 'drop',
                'disabled' => 'no',
            ],
        ];

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $settings
     */
    private function ensureResource(
        Client $client,
        Router $router,
        string $endpoint,
        array $identity,
        string $comment,
        array $settings,
        string $correlationId,
    ): void {
        $rows = $this->getRows($client, $router, $endpoint, $correlationId);
        $managedRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['comment'] ?? null) === $comment
        ));

        if (count($managedRows) > 1) {
            throw RouterProbeException::configuration('Duplicate MMUTech-managed RouterOS entries were found. Review the configuration before retrying.');
        }

        $desired = array_merge($identity, $settings);

        if ($managedRows !== []) {
            $existing = $managedRows[0];

            if ($this->matches($existing, array_merge($desired, ['comment' => $comment]))) {
                return;
            }

            $id = $this->stringOrNull($existing['.id'] ?? null);

            if ($id === null) {
                throw RouterProbeException::configuration('A managed RouterOS entry cannot be updated because it has no identifier.');
            }

            $this->patchResource(
                $client,
                $router,
                $endpoint.'/'.$id,
                array_merge($desired, ['comment' => $comment]),
                $correlationId,
            );

            return;
        }

        $matchingRows = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row, $identity)));

        if ($matchingRows !== []) {
            if (count($matchingRows) === 1 && $this->matches($matchingRows[0], $desired)) {
                return;
            }

            throw RouterProbeException::configuration('A conflicting RouterOS entry already uses the requested configuration name or address.');
        }

        $this->createResource(
            $client,
            $router,
            $endpoint,
            array_merge($desired, ['comment' => $comment]),
            $correlationId,
        );
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     */
    private function matches(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if ($key === 'disabled') {
                if ($this->routerOsBoolean($actual[$key] ?? false) !== $this->routerOsBoolean($value)) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value)) {
                $actualValues = array_map('trim', explode(',', (string) $actual[$key]));
                $expectedValues = array_map('trim', $value);

                if ($actualValues !== $expectedValues) {
                    return false;
                }

                continue;
            }

            if (trim((string) $actual[$key]) !== trim((string) $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $expected
     */
    private function containsMatching(array $rows, array $expected): bool
    {
        foreach ($rows as $row) {
            if ($this->matches($row, $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $rule
     */
    private function findMatchingRulePosition(array $rows, array $rule): ?int
    {
        $comment = $rule['comment'] ?? null;
        $settings = $rule['settings'] ?? $rule;

        foreach ($rows as $index => $row) {
            if ($comment !== null && ($row['comment'] ?? null) === $comment && $this->matches($row, $settings)) {
                return $index;
            }
        }

        foreach ($rows as $index => $row) {
            if ($this->matches($row, $settings)) {
                return $index;
            }
        }

        return null;
    }

    private function check(bool $passed, string $message): array
    {
        return [
            'status' => $passed ? 'passed' : 'failed',
            'message' => $message,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function firstRowId(array $rows): ?string
    {
        foreach ($rows as $row) {
            $id = $this->stringOrNull($row['.id'] ?? null);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function findRowIdByComment(array $rows, string $comment): ?string
    {
        foreach ($rows as $row) {
            if (($row['comment'] ?? null) === $comment) {
                return $this->stringOrNull($row['.id'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createResource(
        Client $client,
        Router $router,
        string $endpoint,
        array $payload,
        string $correlationId,
    ): array {
        $started = hrtime(true);

        try {
            $response = $client->put($endpoint, $payload);
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

            return is_array($response[0] ?? null) ? $response[0] : $response;
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'PUT', $endpoint, $started, $exception, $correlationId);

            throw $exception instanceof RouterProbeException
                ? $exception
                : RouterProbeException::fromThrowable($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function patchResource(
        Client $client,
        Router $router,
        string $endpoint,
        array $payload,
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
                ['fields' => array_keys($payload)],
                [],
                $correlationId
            );
        } catch (Throwable $exception) {
            $this->recordFailure($router, 'PATCH', $endpoint, $started, $exception, $correlationId);

            throw $exception instanceof RouterProbeException
                ? $exception
                : RouterProbeException::fromThrowable($exception);
        }
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

    /**
     * @return array{address: string, prefix: int, network: string, network_long: int, broadcast_long: int, address_long: int}
     */
    private function parseIpv4Cidr(string $cidr, int $minimumPrefix, int $maximumPrefix): array
    {
        $parts = explode('/', trim($cidr));
        $address = $parts[0] ?? '';
        $prefix = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT) : 32;

        if (
            count($parts) > 2
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $prefix === false
            || $prefix < $minimumPrefix
            || $prefix > $maximumPrefix
        ) {
            throw RouterProbeException::configuration('Enter a valid IPv4 address or CIDR range.');
        }

        $addressLong = $this->parseIpv4Address($address);
        $mask = $this->ipv4Mask($prefix);
        $hostMask = 0xFFFFFFFF ^ $mask;
        $networkLong = $addressLong & $mask;

        return [
            'address' => $address,
            'prefix' => $prefix,
            'network' => long2ip($networkLong),
            'network_long' => $networkLong,
            'broadcast_long' => $networkLong | $hostMask,
            'address_long' => $addressLong,
        ];
    }

    private function parseIpv4Address(string $address): int
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw RouterProbeException::configuration('Enter a valid IPv4 address.');
        }

        return (int) sprintf('%u', ip2long($address));
    }

    private function ipv4Mask(int $prefix): int
    {
        return $prefix === 0
            ? 0
            : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    }

    private function routerOsBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function elapsedMs(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
