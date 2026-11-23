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
        $configurator->configure(new Router(['rest_port' => 443]), $configuration, 'correlation-id');
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
        $router = new Router(['rest_port' => 443]);

        $checks = $configurator->configure($router, $this->validConfiguration(), 'correlation-id');
        $writeCount = count($writes);
        $repeatedChecks = $configurator->configure($router, $this->validConfiguration(), 'correlation-id');

        $this->assertSame(14, $writeCount);
        $this->assertCount($writeCount, $writes);
        $this->assertSame('passed', $checks['firewall']['status']);
        $this->assertSame('passed', $checks['firewall_precedence']['status']);
        $this->assertSame('warning', $checks['firewall_conflicts']['status']);
        $this->assertSame('passed', $checks['nat']['status']);
        $this->assertSame('warning', $checks['wan_address']['status']);
        $this->assertSame('warning', $checks['default_route']['status']);
        $this->assertSame('passed', $repeatedChecks['firewall']['status']);

        $verificationChecks = $configurator->verify($router, $this->validConfiguration(), 'correlation-id');

        $this->assertSame($writeCount, count($writes));
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
            new Router(['rest_port' => 443]),
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
                new Router(['rest_port' => 443]),
                $this->validConfiguration(),
                'correlation-id',
            );
            $this->fail('An overlapping LAN subnet must be rejected.');
        } catch (RouterProbeException $exception) {
            $this->assertStringContainsString('overlaps', $exception->getMessage());
        }

        $this->assertSame([], $writes);
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
