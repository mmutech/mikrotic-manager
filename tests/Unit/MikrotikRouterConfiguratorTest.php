<?php

namespace Tests\Unit;

use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Services\Mikrotik\MikrotikRouterConfigurator;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use Mivo\MikrotikRos7\Client;
use PHPUnit\Framework\TestCase;

class MikrotikRouterConfiguratorTest extends TestCase
{
    public function test_it_normalizes_a_site_specific_lan_configuration(): void
    {
        $configuration = $this->configurator()->normalize($this->validConfiguration());

        $this->assertSame('192.168.10.1/24', $configuration['lan_address']);
        $this->assertSame('192.168.10.0/24', $configuration['network_cidr']);
        $this->assertSame('192.168.10.20-192.168.10.200', $configuration['pool_range']);
        $this->assertSame(['10.20.1.0/24'], $configuration['management_cidrs']);
    }

    public function test_it_generates_terminal_commands_for_dhcp_nat_and_ordered_firewall_rules(): void
    {
        $router = $this->router(['rest_port' => 8729]);

        $commands = $this->configurator()->terminalCommands($router, $this->validConfiguration());
        $firewallCommands = array_values(array_filter(
            $commands,
            fn (string $command): bool => str_starts_with($command, '/ip firewall filter add ')
        ));

        $this->assertSame(
            '/ip address add address=192.168.10.1/24 interface=bridge-lan disabled=no comment="MMUTech managed LAN address"',
            $commands[0],
        );
        $this->assertContains(
            '/ip pool add name=Branch-HQ ranges=192.168.10.20-192.168.10.200 comment="MMUTech managed LAN DHCP pool"',
            $commands,
        );
        $this->assertContains(
            '/ip dhcp-server add name=mmutech-lan-dhcp interface=bridge-lan address-pool=Branch-HQ lease-time=1d disabled=no comment="MMUTech managed LAN DHCP server"',
            $commands,
        );
        $this->assertContains(
            '/ip firewall nat add chain=srcnat action=masquerade src-address=192.168.10.0/24 out-interface=ether1 disabled=no comment="MMUTech managed LAN masquerade" place-before=0',
            $commands,
        );
        $this->assertCount(9, $firewallCommands);
        $this->assertStringContainsString('chain=forward', $firewallCommands[0]);
        $this->assertStringContainsString('connection-state=new', $firewallCommands[0]);
        $this->assertStringContainsString('connection-nat-state=!dstnat', $firewallCommands[0]);
        $this->assertStringContainsString('chain=input connection-state=established,related,untracked', $firewallCommands[8]);
        $this->assertStringContainsString('src-address=10.20.1.0/24 protocol=tcp dst-port=8729', $firewallCommands[7]);
        $this->assertContains('/ip firewall filter print detail', $commands);
    }

    public function test_it_requires_impact_confirmation_before_generating_terminal_commands(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['management_impact_confirmed'] = false;

        $this->expectException(RouterProbeException::class);
        $this->configurator()->terminalCommands($this->router(), $configuration);
    }

    public function test_it_quotes_routeros_interface_names_with_spaces(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['wan_interface'] = 'WAN uplink';

        $commands = $this->configurator()->terminalCommands($this->router(), $configuration);
        $natCommand = collect($commands)->first(
            fn (string $command): bool => str_starts_with($command, '/ip firewall nat add ')
        );

        $this->assertIsString($natCommand);
        $this->assertStringContainsString('out-interface="WAN uplink"', $natCommand);
    }

    public function test_it_uses_the_exact_router_identity_for_the_lan_pool(): void
    {
        $router = $this->router(['identity' => 'Branch HQ']);

        $commands = $this->configurator()->terminalCommands($router, $this->validConfiguration());

        $this->assertContains(
            '/ip pool add name="Branch HQ" ranges=192.168.10.20-192.168.10.200 comment="MMUTech managed LAN DHCP pool"',
            $commands,
        );
        $this->assertContains(
            '/ip dhcp-server add name=mmutech-lan-dhcp interface=bridge-lan address-pool="Branch HQ" lease-time=1d disabled=no comment="MMUTech managed LAN DHCP server"',
            $commands,
        );
    }

    public function test_it_rejects_control_characters_in_terminal_interface_names(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['wan_interface'] = "ether1\n/ip system reboot";

        $this->expectException(RouterProbeException::class);
        $this->configurator()->normalize($configuration);
    }

    public function test_it_rejects_a_dhcp_pool_that_contains_the_router_address(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['pool_start'] = '192.168.10.1';

        $this->expectException(RouterProbeException::class);
        $this->configurator()->normalize($configuration);
    }

    public function test_it_rejects_a_lan_gateway_that_is_the_subnet_address(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['lan_address'] = '192.168.10.0/24';

        $this->expectException(RouterProbeException::class);
        $this->configurator()->normalize($configuration);
    }

    public function test_it_rejects_a_management_cidr_broader_than_a_slash_eight(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['management_cidrs'] = ['0.0.0.0/0'];

        $this->expectException(RouterProbeException::class);
        $this->configurator()->normalize($configuration);
    }

    public function test_it_requires_impact_confirmation_before_connecting_to_the_router(): void
    {
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->never())->method('make');

        $configurator = new MikrotikRouterConfigurator(
            $connections,
            $this->createMock(RouterApiRecorder::class),
        );
        $configuration = $this->validConfiguration();
        $configuration['management_impact_confirmed'] = false;

        $this->expectException(RouterProbeException::class);
        $configurator->configure($this->router(), $configuration, 'correlation-id');
    }

    public function test_it_configures_idempotently_and_verifies_routeros_readback(): void
    {
        $state = [
            '/rest/interface' => [
                ['name' => 'bridge-lan', 'type' => 'bridge'],
                ['name' => 'ether1', 'type' => 'ether'],
            ],
            '/rest/interface/bridge/port' => [
                ['.id' => '*1', 'bridge' => 'bridge-lan', 'interface' => 'ether2'],
            ],
            '/rest/ip/firewall/filter' => [
                [
                    '.id' => '*9',
                    'chain' => 'input',
                    'connection-state' => 'established,related,untracked',
                    'action' => 'accept',
                    'disabled' => 'no',
                    'comment' => 'router-managed established rule',
                ],
                [
                    '.id' => '*10',
                    'chain' => 'input',
                    'in-interface' => 'ether1',
                    'protocol' => 'udp',
                    'dst-port' => '500',
                    'action' => 'accept',
                    'comment' => 'existing VPN service',
                ],
            ],
        ];
        $writes = [];
        $configurator = $this->configuratorWithRouterState($state, $writes);
        $router = $this->router();

        $checks = $configurator->configure($router, $this->validConfiguration(), 'correlation-id');
        $writeCount = count($writes);
        $repeatedChecks = $configurator->configure($router, $this->validConfiguration(), 'correlation-id');

        $this->assertSame(14, $writeCount);
        $this->assertCount($writeCount, $writes);
        $poolWrite = collect($writes)->firstWhere('endpoint', '/rest/ip/pool');
        $dhcpServerWrite = collect($writes)->firstWhere('endpoint', '/rest/ip/dhcp-server');
        $this->assertSame('Branch-HQ', $poolWrite['payload']['name']);
        $this->assertSame('Branch-HQ', $dhcpServerWrite['payload']['address-pool']);
        $this->assertSame('passed', $checks['firewall']['status']);
        $this->assertSame('passed', $checks['firewall_precedence']['status']);
        $this->assertSame('warning', $checks['firewall_conflicts']['status']);
        $this->assertSame('passed', $checks['nat']['status']);
        $this->assertSame('warning', $checks['wan_address']['status']);
        $this->assertSame('warning', $checks['default_route']['status']);
        $this->assertSame('passed', $repeatedChecks['firewall']['status']);

        $verificationChecks = $configurator->verify($router, $this->validConfiguration(), 'correlation-id');

        $this->assertSame($writeCount, count($writes));
        $this->assertSame('passed', $verificationChecks['dhcp_pool']['status']);
        $this->assertSame('passed', $verificationChecks['dhcp_server']['status']);
        $this->assertSame('passed', $verificationChecks['firewall']['status']);
        $forwardWanRule = collect($state['/rest/ip/firewall/filter'])
            ->firstWhere('comment', 'MMUTech managed firewall forward drop WAN');
        $this->assertSame('new', $forwardWanRule['connection-state']);
        $this->assertSame('!dstnat', $forwardWanRule['connection-nat-state']);
        $this->assertSame('router-managed established rule', $state['/rest/ip/firewall/filter'][9]['comment']);
        $natWrite = collect($writes)->firstWhere('endpoint', '/rest/ip/firewall/nat');
        $this->assertArrayNotHasKey('place-before', $natWrite['payload']);
        $filterWrites = collect($writes)
            ->where('endpoint', '/rest/ip/firewall/filter')
            ->pluck('payload.comment')
            ->values()
            ->all();
        $this->assertSame('MMUTech managed firewall input management 1', $filterWrites[0]);
        $this->assertLessThan(
            array_search('MMUTech managed firewall input drop WAN', $filterWrites, true),
            array_search('MMUTech managed firewall input management 1', $filterWrites, true),
        );
    }

    public function test_it_adds_management_accepts_before_drop_rules_on_an_empty_filter_table(): void
    {
        $state = [
            '/rest/interface' => [
                ['name' => 'bridge-lan', 'type' => 'bridge'],
                ['name' => 'ether1', 'type' => 'ether'],
            ],
            '/rest/interface/bridge/port' => [
                ['.id' => '*1', 'bridge' => 'bridge-lan', 'interface' => 'ether2'],
            ],
        ];
        $writes = [];
        $configurator = $this->configuratorWithRouterState($state, $writes);
        $checks = $configurator->configure(
            $this->router(),
            $this->validConfiguration(),
            'correlation-id',
        );
        $comments = collect($state['/rest/ip/firewall/filter'])->pluck('comment')->values()->all();

        $this->assertLessThan(
            array_search('MMUTech managed firewall input drop WAN', $comments, true),
            array_search('MMUTech managed firewall input management 1', $comments, true),
        );
        $this->assertSame('passed', $checks['firewall']['status']);
        $this->assertSame('passed', $checks['firewall_conflicts']['status']);
    }

    public function test_it_reorders_managed_firewall_rules_and_moves_the_masquerade_rule_first(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['management_cidrs'] = ['192.168.2.70/32'];
        $rules = $this->expectedFirewallRuleDefinitions('192.168.2.70/32');
        $filterRows = [];

        foreach (array_reverse($rules) as $index => $rule) {
            $filterRows[] = array_merge(
                ['.id' => '*'.(30 + $index)],
                $rule['settings'],
                ['comment' => $rule['comment']],
            );
        }

        foreach ($filterRows as &$row) {
            if (($row['comment'] ?? null) === 'MMUTech managed firewall input management 1') {
                $row['src-address'] = '192.168.2.70';
            }
        }
        unset($row);

        $state = [
            '/rest/interface' => [
                ['name' => 'bridge-lan', 'type' => 'bridge'],
                ['name' => 'ether1', 'type' => 'ether'],
            ],
            '/rest/interface/bridge/port' => [
                ['.id' => '*1', 'bridge' => 'bridge-lan', 'interface' => 'ether2'],
            ],
            '/rest/ip/address' => [
                [
                    '.id' => '*2',
                    'address' => '192.168.10.1/24',
                    'interface' => 'bridge-lan',
                    'disabled' => 'no',
                    'comment' => 'MMUTech managed LAN address',
                ],
            ],
            '/rest/ip/pool' => [
                [
                    '.id' => '*3',
                    'name' => 'Branch-HQ',
                    'ranges' => '192.168.10.20-192.168.10.200',
                    'comment' => 'MMUTech managed LAN DHCP pool',
                ],
            ],
            '/rest/ip/dhcp-server' => [
                [
                    '.id' => '*4',
                    'name' => 'mmutech-lan-dhcp',
                    'interface' => 'bridge-lan',
                    'address-pool' => 'Branch-HQ',
                    'lease-time' => '1d',
                    'disabled' => 'no',
                    'comment' => 'MMUTech managed LAN DHCP server',
                ],
            ],
            '/rest/ip/dhcp-server/network' => [
                [
                    '.id' => '*5',
                    'address' => '192.168.10.0/24',
                    'gateway' => '192.168.10.1',
                    'dns-server' => '1.1.1.1,8.8.8.8',
                    'comment' => 'MMUTech managed LAN DHCP network',
                ],
            ],
            '/rest/ip/firewall/nat' => [
                [
                    '.id' => '*6',
                    'chain' => 'srcnat',
                    'action' => 'masquerade',
                    'src-address' => '0.0.0.0/0',
                    'out-interface' => 'ether1',
                    'disabled' => 'no',
                    'comment' => 'other srcnat rule',
                ],
                [
                    '.id' => '*7',
                    'chain' => 'srcnat',
                    'action' => 'masquerade',
                    'src-address' => '192.168.10.0/24',
                    'out-interface' => 'ether1',
                    'disabled' => 'no',
                    'comment' => 'MMUTech managed LAN masquerade',
                ],
            ],
            '/rest/ip/firewall/filter' => $filterRows,
        ];
        $writes = [];
        $configurator = $this->configuratorWithRouterState($state, $writes);
        $router = $this->router();

        $before = $configurator->verify($router, $configuration, 'correlation-id');

        $this->assertSame([], $writes);
        $this->assertSame('failed', $before['firewall']['status']);
        $this->assertSame('warning', $before['nat_precedence']['status']);

        $checks = $configurator->configure($router, $configuration, 'correlation-id');

        $this->assertSame('passed', $checks['firewall']['status']);
        $this->assertSame('passed', $checks['firewall_precedence']['status']);
        $this->assertSame('passed', $checks['nat_precedence']['status']);
        $this->assertSame(
            array_column($rules, 'comment'),
            collect($state['/rest/ip/firewall/filter'])->pluck('comment')->values()->all(),
        );
        $this->assertSame('MMUTech managed LAN masquerade', $state['/rest/ip/firewall/nat'][0]['comment']);
        $this->assertContains(
            '/rest/ip/firewall/nat/move',
            collect($writes)->pluck('endpoint')->all(),
        );
        $this->assertSame(
            8,
            collect($writes)->filter(
                fn (array $write): bool => $write['endpoint'] === '/rest/ip/firewall/filter/move'
            )->count(),
        );

        $writesBeforeVerify = count($writes);
        $configurator->verify($router, $configuration, 'correlation-id');

        $this->assertCount($writesBeforeVerify, $writes);
    }

    public function test_it_stops_on_an_overlapping_lan_subnet_before_writing(): void
    {
        $state = [
            '/rest/interface' => [
                ['name' => 'bridge-lan', 'type' => 'bridge'],
                ['name' => 'ether1', 'type' => 'ether'],
            ],
            '/rest/interface/bridge/port' => [
                ['.id' => '*1', 'bridge' => 'bridge-lan', 'interface' => 'ether2'],
            ],
            '/rest/ip/address' => [
                ['.id' => '*2', 'address' => '192.168.10.2/24', 'interface' => 'ether5', 'disabled' => 'no'],
            ],
        ];
        $writes = [];
        $configurator = $this->configuratorWithRouterState($state, $writes);

        try {
            $configurator->configure(
                $this->router(),
                $this->validConfiguration(),
                'correlation-id',
            );
            $this->fail('An overlapping LAN subnet must be rejected.');
        } catch (RouterProbeException $exception) {
            $this->assertStringContainsString('overlaps', $exception->getMessage());
        }

        $this->assertSame([], $writes);
    }

    /** @param array<string, mixed> $attributes */
    private function router(array $attributes = []): Router
    {
        return new Router(array_merge([
            'name' => 'Branch Router',
            'identity' => 'Branch-HQ',
            'rest_port' => 443,
        ], $attributes));
    }

    private function configurator(): MikrotikRouterConfigurator
    {
        return new MikrotikRouterConfigurator(
            $this->createMock(RouterConnectionFactory::class),
            $this->createMock(RouterApiRecorder::class),
        );
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $state
     * @param  array<int, array{endpoint: string, payload: array<string, mixed>}>  $writes
     */
    private function configuratorWithRouterState(array &$state, array &$writes): MikrotikRouterConfigurator
    {
        $client = $this->createMock(Client::class);
        $client->method('get')->willReturnCallback(
            function (string $endpoint) use (&$state): array {
                return $state[$endpoint] ?? [];
            }
        );
        $client->method('put')->willReturnCallback(
            function (string $endpoint, array $payload) use (&$state, &$writes): array {
                $anchor = $payload['place-before'] ?? '0';
                unset($payload['place-before']);
                $id = '*'.(count($writes) + 10);
                $row = array_merge($payload, ['.id' => $id]);
                $state[$endpoint] ??= [];

                if ($endpoint === '/rest/ip/firewall/filter') {
                    $position = 0;

                    foreach ($state[$endpoint] as $index => $existing) {
                        if (($existing['.id'] ?? null) === $anchor) {
                            $position = $index;

                            break;
                        }

                        $position = $index + 1;
                    }

                    array_splice($state[$endpoint], $position, 0, [$row]);
                } else {
                    $state[$endpoint][] = $row;
                }

                $writes[] = ['endpoint' => $endpoint, 'payload' => $payload];

                return [$row];
            }
        );
        $client->method('post')->willReturnCallback(
            function (string $endpoint, array $payload) use (&$state, &$writes): array {
                $resource = substr($endpoint, 0, -strlen('/move'));
                $id = $payload['.id'] ?? null;
                $destinationId = $payload['destination'] ?? null;
                $rows = $state[$resource] ?? [];
                $from = null;

                foreach ($rows as $index => $row) {
                    if (($row['.id'] ?? null) === $id) {
                        $from = $index;

                        break;
                    }
                }

                if ($from !== null) {
                    $movingRow = $rows[$from];
                    array_splice($rows, $from, 1);
                    $destinationIndex = null;

                    foreach ($rows as $index => $row) {
                        if (($row['.id'] ?? null) === $destinationId) {
                            $destinationIndex = $index;

                            break;
                        }
                    }

                    if ($destinationIndex !== null) {
                        array_splice($rows, $destinationIndex, 0, [$movingRow]);
                    }

                    $state[$resource] = $rows;
                }

                $writes[] = ['endpoint' => $endpoint, 'payload' => $payload];

                return [];
            }
        );
        $client->method('patch')->willReturnCallback(
            function (string $endpoint, array $payload) use (&$state): array {
                $separator = strrpos($endpoint, '/');
                $resource = substr($endpoint, 0, $separator);
                $id = substr($endpoint, $separator + 1);

                foreach ($state[$resource] ?? [] as $index => $row) {
                    if (($row['.id'] ?? null) === $id) {
                        $state[$resource][$index] = array_merge($row, $payload);

                        break;
                    }
                }

                return [];
            }
        );

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->method('make')->willReturn($client);

        return new MikrotikRouterConfigurator(
            $connections,
            $this->createMock(RouterApiRecorder::class),
        );
    }

    /** @return array<int, array{comment: string, settings: array<string, string>}> */
    private function expectedFirewallRuleDefinitions(string $managementCidr = '10.20.1.0/24'): array
    {
        return [
            [
                'comment' => 'MMUTech managed firewall input established',
                'settings' => ['chain' => 'input', 'connection-state' => 'established,related,untracked', 'action' => 'accept', 'disabled' => 'no'],
            ],
            [
                'comment' => 'MMUTech managed firewall input management 1',
                'settings' => ['chain' => 'input', 'src-address' => $managementCidr, 'protocol' => 'tcp', 'dst-port' => '443', 'action' => 'accept', 'disabled' => 'no'],
            ],
            [
                'comment' => 'MMUTech managed firewall input invalid',
                'settings' => ['chain' => 'input', 'connection-state' => 'invalid', 'action' => 'drop', 'disabled' => 'no'],
            ],
            [
                'comment' => 'MMUTech managed firewall input DHCP from LAN',
                'settings' => ['chain' => 'input', 'in-interface' => 'bridge-lan', 'protocol' => 'udp', 'dst-port' => '67', 'action' => 'accept', 'disabled' => 'no'],
            ],
            [
                'comment' => 'MMUTech managed firewall input drop WAN',
                'settings' => ['chain' => 'input', 'in-interface' => 'ether1', 'action' => 'drop', 'disabled' => 'no'],
            ],
            [
                'comment' => 'MMUTech managed firewall forward established',
                'settings' => ['chain' => 'forward', 'connection-state' => 'established,related,untracked', 'action' => 'accept', 'disabled' => 'no'],
            ],
            [
                'comment' => 'MMUTech managed firewall forward invalid',
                'settings' => ['chain' => 'forward', 'connection-state' => 'invalid', 'action' => 'drop', 'disabled' => 'no'],
            ],
            [
                'comment' => 'MMUTech managed firewall forward LAN to WAN',
                'settings' => ['chain' => 'forward', 'in-interface' => 'bridge-lan', 'out-interface' => 'ether1', 'src-address' => '192.168.10.0/24', 'action' => 'accept', 'disabled' => 'no'],
            ],
            [
                'comment' => 'MMUTech managed firewall forward drop WAN',
                'settings' => ['chain' => 'forward', 'in-interface' => 'ether1', 'connection-state' => 'new', 'connection-nat-state' => '!dstnat', 'action' => 'drop', 'disabled' => 'no'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validConfiguration(): array
    {
        return [
            'bridge_name' => 'bridge-lan',
            'wan_interface' => 'ether1',
            'lan_address' => '192.168.10.1/24',
            'pool_start' => '192.168.10.20',
            'pool_end' => '192.168.10.200',
            'dns_servers' => ['1.1.1.1', '8.8.8.8'],
            'management_cidrs' => ['10.20.1.15/24'],
            'management_impact_confirmed' => true,
        ];
    }
}
