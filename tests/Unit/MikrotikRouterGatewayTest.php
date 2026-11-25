<?php

namespace Tests\Unit;

use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Services\Mikrotik\DetailedMikrotikClient;
use App\Services\Mikrotik\MikrotikRouterGateway;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use App\Support\HotspotServerConfiguration;
use Mivo\MikrotikRos7\Client;
use PHPUnit\Framework\TestCase;

class MikrotikRouterGatewayTest extends TestCase
{
    public function test_it_writes_the_requested_routeros_identity(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('post')
            ->with('/rest/system/identity/set', ['name' => 'Branch Router'])
            ->willReturn([]);

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())
            ->method('make')
            ->with($router)
            ->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->once())
            ->method('success')
            ->with(
                $router,
                'POST',
                '/rest/system/identity/set',
                $this->isType('int'),
                200,
                ['identity_present' => true],
                [],
                'correlation-id',
            );

        $gateway = new MikrotikRouterGateway($connections, $recorder);
        $gateway->setIdentity($router, 'Branch Router', 'correlation-id');
    }

    public function test_it_discovers_ethernet_ports_and_reports_existing_bridge_membership(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(fn (string $endpoint): array => match ($endpoint) {
                '/rest/interface' => [
                    ['name' => 'ether2', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false'],
                    ['name' => 'ether1', 'type' => 'ether', 'running' => 'false', 'disabled' => 'true'],
                    ['name' => 'bridge-lan', 'type' => 'bridge'],
                    ['name' => 'vlan10', 'type' => 'vlan'],
                ],
                '/rest/interface/bridge/port' => [
                    ['interface' => 'ether2', 'bridge' => 'bridge-lan'],
                ],
                default => [],
            });

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(2))->method('success');

        $interfaces = (new MikrotikRouterGateway($connections, $recorder))
            ->ethernetInterfaces($router, 'correlation-id');

        $this->assertSame([
            ['name' => 'ether1', 'running' => false, 'disabled' => true, 'bridge' => null],
            ['name' => 'ether2', 'running' => true, 'disabled' => false, 'bridge' => 'bridge-lan'],
        ], $interfaces);
    }

    public function test_it_discovers_current_dhcp_nat_and_firewall_configuration(): void
    {
        $router = new Router(['name' => 'Branch Router', 'identity' => 'Branch-HQ']);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(6))
            ->method('get')
            ->willReturnCallback(fn (string $endpoint): array => match ($endpoint) {
                '/rest/ip/address' => [
                    [
                        '.id' => '*1',
                        'address' => '192.168.88.1/24',
                        'interface' => 'bridge-lan',
                        'network' => '192.168.88.0',
                        'disabled' => 'false',
                        'comment' => 'MMUTech managed LAN address',
                    ],
                ],
                '/rest/ip/pool' => [
                    [
                        '.id' => '*2',
                        'name' => 'Branch-HQ',
                        'ranges' => '192.168.88.20-192.168.88.200',
                        'comment' => 'MMUTech managed LAN DHCP pool',
                    ],
                ],
                '/rest/ip/dhcp-server/network' => [
                    [
                        '.id' => '*3',
                        'address' => '192.168.88.0/24',
                        'gateway' => '192.168.88.1',
                        'dns-server' => '1.1.1.1,8.8.8.8',
                        'comment' => 'MMUTech managed LAN DHCP network',
                    ],
                ],
                '/rest/ip/dhcp-server' => [
                    [
                        '.id' => '*4',
                        'name' => 'mmutech-lan-dhcp',
                        'interface' => 'bridge-lan',
                        'address-pool' => 'Branch-HQ',
                        'lease-time' => '1d',
                        'disabled' => 'false',
                        'comment' => 'MMUTech managed LAN DHCP server',
                    ],
                ],
                '/rest/ip/firewall/nat' => [
                    [
                        '.id' => '*5',
                        'chain' => 'srcnat',
                        'action' => 'masquerade',
                        'src-address' => '192.168.88.0/24',
                        'out-interface' => 'ether1',
                        'disabled' => 'false',
                        'comment' => 'MMUTech managed LAN masquerade',
                    ],
                ],
                '/rest/ip/firewall/filter' => [
                    [
                        '.id' => '*6',
                        'chain' => 'input',
                        'action' => 'accept',
                        'src-address' => '10.0.0.0/8',
                        'in-interface' => 'ether1',
                        'connection-state' => 'established',
                        'disabled' => 'false',
                        'comment' => 'MMUTech managed firewall input established',
                    ],
                ],
                default => [],
            });

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(6))->method('success');

        $discovery = (new MikrotikRouterGateway($connections, $recorder))
            ->networkConfiguration($router, 'correlation-id');

        $this->assertSame([
            'addresses' => [
                [
                    'id' => '*1',
                    'title' => '192.168.88.1/24',
                    'detail' => 'bridge-lan · 192.168.88.0 · enabled',
                    'comment' => 'MMUTech managed LAN address',
                    'disabled' => false,
                    'managed' => true,
                ],
            ],
            'pools' => [
                [
                    'id' => '*2',
                    'title' => 'Branch-HQ',
                    'detail' => '192.168.88.20-192.168.88.200',
                    'comment' => 'MMUTech managed LAN DHCP pool',
                    'disabled' => false,
                    'managed' => true,
                ],
            ],
            'dhcp_networks' => [
                [
                    'id' => '*3',
                    'title' => '192.168.88.0/24',
                    'detail' => 'gateway 192.168.88.1 · DNS 1.1.1.1,8.8.8.8',
                    'comment' => 'MMUTech managed LAN DHCP network',
                    'disabled' => false,
                    'managed' => true,
                ],
            ],
            'dhcp_servers' => [
                [
                    'id' => '*4',
                    'title' => 'mmutech-lan-dhcp',
                    'detail' => 'bridge-lan · Branch-HQ · lease 1d · enabled',
                    'comment' => 'MMUTech managed LAN DHCP server',
                    'disabled' => false,
                    'managed' => true,
                ],
            ],
            'nat_rules' => [
                [
                    'id' => '*5',
                    'title' => 'srcnat → masquerade',
                    'detail' => '192.168.88.0/24 · ether1 · enabled',
                    'comment' => 'MMUTech managed LAN masquerade',
                    'disabled' => false,
                    'managed' => true,
                ],
            ],
            'firewall_rules' => [
                [
                    'id' => '*6',
                    'title' => 'input → accept',
                    'detail' => '10.0.0.0/8 · ether1 · established · enabled',
                    'comment' => 'MMUTech managed firewall input established',
                    'disabled' => false,
                    'managed' => true,
                ],
            ],
        ], $discovery);
    }

    public function test_it_detects_the_server_source_network_from_the_current_routeros_connection(): void
    {
        $router = new Router(['name' => 'Branch Router', 'rest_port' => 8729]);
        $client = $this->createMock(DetailedMikrotikClient::class);
        $client->expects($this->once())
            ->method('lastLocalPort')
            ->willReturn(54321);
        $client->expects($this->exactly(3))
            ->method('get')
            ->willReturnCallback(fn (string $endpoint): array => match ($endpoint) {
                '/rest/ip/firewall/connection' => [
                    [
                        'protocol' => 'tcp',
                        'dst-port' => '8729',
                        'src-port' => '54321',
                        'src-address' => '10.20.1.15',
                        'invalid' => 'false',
                    ],
                    [
                        'protocol' => 'tcp',
                        'dst-port' => '8729',
                        'src-port' => '54322',
                        'src-address' => '203.0.113.99',
                        'invalid' => 'false',
                    ],
                    [
                        'protocol' => 'udp',
                        'dst-port' => '8729',
                        'src-port' => '54321',
                        'src-address' => '198.51.100.25',
                        'invalid' => 'false',
                    ],
                ],
                '/rest/ip/firewall/filter' => [
                    [
                        'src-address' => '10.20.1.0/24',
                        'disabled' => 'false',
                        'comment' => 'MMUTech managed firewall input management 1',
                    ],
                    [
                        'src-address' => '203.0.113.0/24',
                        'disabled' => 'false',
                        'comment' => 'MMUTech managed firewall input management 2',
                    ],
                ],
                '/rest/ip/service' => [
                    [
                        'name' => 'api-ssl',
                        'port' => '8729',
                        'disabled' => 'false',
                        'available-from' => '10.20.1.0/24, 203.0.113.0/24',
                    ],
                ],
                default => [],
            });

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(3))->method('success');

        $cidrs = (new MikrotikRouterGateway($connections, $recorder))
            ->managementSourceCidrs($router, 'correlation-id');

        $this->assertSame(['10.20.1.0/24'], $cidrs);
    }

    public function test_it_falls_back_to_a_server_source_host_when_no_existing_cidr_matches(): void
    {
        $router = new Router(['name' => 'Branch Router', 'rest_port' => 8729]);
        $client = $this->createMock(DetailedMikrotikClient::class);
        $client->expects($this->once())
            ->method('lastLocalPort')
            ->willReturn(54321);
        $client->expects($this->exactly(3))
            ->method('get')
            ->willReturnCallback(fn (string $endpoint): array => match ($endpoint) {
                '/rest/ip/firewall/connection' => [[
                    'protocol' => 'tcp',
                    'dst-port' => '8729',
                    'src-port' => '54321',
                    'src-address' => '198.51.100.25',
                    'invalid' => 'false',
                ]],
                '/rest/ip/firewall/filter' => [],
                '/rest/ip/service' => [],
                default => [],
            });

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(3))->method('success');

        $cidrs = (new MikrotikRouterGateway($connections, $recorder))
            ->managementSourceCidrs($router, 'correlation-id');

        $this->assertSame(['198.51.100.25/32'], $cidrs);
    }

    public function test_it_creates_the_bridge_and_adds_only_selected_ethernet_ports(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $writes = [];
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(fn (string $endpoint): array => $endpoint === '/rest/interface'
                ? [
                    ['name' => 'ether2', 'type' => 'ether'],
                    ['name' => 'ether3', 'type' => 'ether'],
                    ['name' => 'ether4', 'type' => 'ether'],
                ]
                : []);
        $client->expects($this->exactly(3))
            ->method('put')
            ->willReturnCallback(function (string $endpoint, array $payload) use (&$writes): array {
                $writes[] = [$endpoint, $payload];

                return [];
            });

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(5))->method('success');

        (new MikrotikRouterGateway($connections, $recorder))->configureBridge(
            $router,
            'bridge-lan',
            ['ether2', 'ether3'],
            'correlation-id',
        );

        $this->assertSame([
            ['/rest/interface/bridge', ['name' => 'bridge-lan']],
            ['/rest/interface/bridge/port', ['bridge' => 'bridge-lan', 'interface' => 'ether2']],
            ['/rest/interface/bridge/port', ['bridge' => 'bridge-lan', 'interface' => 'ether3']],
        ], $writes);
    }

    public function test_it_reuses_the_bridge_and_skips_ports_already_attached_to_it(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $writes = [];
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(fn (string $endpoint): array => $endpoint === '/rest/interface'
                ? [
                    ['name' => 'bridge-lan', 'type' => 'bridge'],
                    ['name' => 'ether2', 'type' => 'ether'],
                    ['name' => 'ether3', 'type' => 'ether'],
                ]
                : [
                    ['interface' => 'ether2', 'bridge' => 'bridge-lan'],
                ]);
        $client->expects($this->once())
            ->method('put')
            ->willReturnCallback(function (string $endpoint, array $payload) use (&$writes): array {
                $writes[] = [$endpoint, $payload];

                return [];
            });

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(3))->method('success');

        (new MikrotikRouterGateway($connections, $recorder))->configureBridge(
            $router,
            'bridge-lan',
            ['ether2', 'ether3'],
            'correlation-id',
        );

        $this->assertSame([
            ['/rest/interface/bridge/port', ['bridge' => 'bridge-lan', 'interface' => 'ether3']],
        ], $writes);
    }

    public function test_it_refuses_to_move_an_ethernet_port_from_a_different_bridge(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(fn (string $endpoint): array => $endpoint === '/rest/interface'
                ? [
                    ['name' => 'bridge-lan', 'type' => 'bridge'],
                    ['name' => 'bridge-wan', 'type' => 'bridge'],
                    ['name' => 'ether2', 'type' => 'ether'],
                ]
                : [
                    ['interface' => 'ether2', 'bridge' => 'bridge-wan'],
                ]);
        $client->expects($this->never())->method('put');

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(2))->method('success');

        $this->expectException(RouterProbeException::class);

        (new MikrotikRouterGateway($connections, $recorder))->configureBridge(
            $router,
            'bridge-lan',
            ['ether2'],
            'correlation-id',
        );
    }

    public function test_it_lists_router_hotspot_user_profiles_from_the_user_profile_endpoint(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('get')
            ->with('/rest/ip/hotspot/user/profile')
            ->willReturn([
                ['name' => 'trial', 'rate-limit' => '512k/2048k', 'shared-users' => '2'],
                ['name' => 'default'],
            ]);

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->once())->method('success');

        $profiles = (new MikrotikRouterGateway($connections, $recorder))
            ->hotspotUserProfiles($router, 'correlation-id');

        $this->assertSame([
            ['name' => 'default', 'rate_limit' => null, 'shared_users' => '1'],
            ['name' => 'trial', 'rate_limit' => '512k/2048k', 'shared_users' => '2'],
        ], $profiles);
    }

    public function test_it_enables_cookie_login_and_preserves_existing_hotspot_login_methods(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $before = [
            ['.id' => '*1', 'name' => 'guest', 'login-by' => 'http-chap,cookie', 'http-cookie-lifetime' => '3650d 00:00:00'],
            ['.id' => '*2', 'name' => 'staff', 'login-by' => 'mac', 'http-cookie-lifetime' => '3d'],
        ];
        $after = [
            ['.id' => '*1', 'name' => 'guest', 'login-by' => 'http-chap,cookie', 'http-cookie-lifetime' => '3650d 00:00:00'],
            ['.id' => '*2', 'name' => 'staff', 'login-by' => 'mac,http-chap,cookie', 'http-cookie-lifetime' => '3650d 00:00:00'],
        ];
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->with('/rest/ip/hotspot/profile')
            ->willReturnOnConsecutiveCalls($before, $after);

        $patchCalls = [];
        $client->expects($this->once())
            ->method('patch')
            ->willReturnCallback(function (string $endpoint, array $payload) use (&$patchCalls): array {
                $patchCalls[] = [$endpoint, $payload];

                return [];
            });

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(3))->method('success');

        (new MikrotikRouterGateway($connections, $recorder))
            ->enableHotspotCookieLogin($router, 'correlation-id');

        $this->assertSame([
            ['/rest/ip/hotspot/profile/*2', [
                'login-by' => 'mac,http-chap,cookie',
                'http-cookie-lifetime' => '3650d',
            ]],
        ], $patchCalls);
    }

    public function test_it_creates_and_verifies_a_standalone_hotspot_user_profile(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [],
                [[
                    '.id' => '*1',
                    'name' => 'staff-access',
                    'rate-limit' => '512k/2048k',
                    'shared-users' => '2',
                ]],
            );
        $client->expects($this->once())
            ->method('put')
            ->with('/rest/ip/hotspot/user/profile', [
                'name' => 'staff-access',
                'shared-users' => '2',
                'rate-limit' => '512k/2048k',
            ])
            ->willReturn([]);

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(3))->method('success');

        (new MikrotikRouterGateway($connections, $recorder))->createHotspotUserProfile(
            $router,
            'staff-access',
            '512k/2048k',
            2,
            'correlation-id',
        );
    }

    public function test_it_canonicalizes_profile_rate_limits_before_creation(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [],
                [[
                    '.id' => '*1',
                    'name' => 'mixed-units',
                    'rate-limit' => '512k/2000k',
                    'shared-users' => '1',
                ]],
            );
        $client->expects($this->once())
            ->method('put')
            ->with('/rest/ip/hotspot/user/profile', [
                'name' => 'mixed-units',
                'shared-users' => '1',
                'rate-limit' => '512k/2000k',
            ])
            ->willReturn([]);

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(3))->method('success');

        (new MikrotikRouterGateway($connections, $recorder))->createHotspotUserProfile(
            $router,
            'mixed-units',
            '512k/2M',
            1,
            'correlation-id',
        );
    }

    public function test_it_creates_an_unlimited_profile_and_reuses_identical_settings(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [],
                [['.id' => '*1', 'name' => 'guest', 'shared-users' => '1', 'rate-limit' => '']],
                [['.id' => '*1', 'name' => 'guest', 'shared-users' => '1', 'rate-limit' => '']],
            );
        $client->expects($this->once())
            ->method('put')
            ->with('/rest/ip/hotspot/user/profile', [
                'name' => 'guest',
                'shared-users' => '1',
            ])
            ->willReturn([]);

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->exactly(2))->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(4))->method('success');

        $gateway = new MikrotikRouterGateway($connections, $recorder);
        $gateway->createHotspotUserProfile($router, 'guest', null, 1, 'correlation-id');
        $gateway->createHotspotUserProfile($router, 'guest', null, 1, 'correlation-id');
    }

    public function test_it_refuses_to_change_an_existing_hotspot_user_profile(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('get')
            ->willReturn([[
                '.id' => '*1',
                'name' => 'guest',
                'rate-limit' => '256k/1024k',
                'shared-users' => '1',
            ]]);
        $client->expects($this->never())->method('put');
        $client->expects($this->never())->method('patch');

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->once())->method('success');

        $this->expectException(RouterProbeException::class);

        (new MikrotikRouterGateway($connections, $recorder))->createHotspotUserProfile(
            $router,
            'guest',
            '512k/2048k',
            1,
            'correlation-id',
        );
    }

    public function test_it_creates_and_verifies_the_managed_hotspot_server(): void
    {
        $router = new Router(['name' => 'Branch Router', 'identity' => 'JEDC-HQ']);
        $serverName = HotspotServerConfiguration::nameFromRouter($router);
        $poolName = HotspotServerConfiguration::addressPoolFromRouter($router);
        $configuration = [
            'server_name' => $serverName,
            'interface' => 'bridge-lan',
            'address_pool' => $poolName,
            'profile' => 'default',
        ];
        $hotspotReads = 0;
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(4))
            ->method('get')
            ->willReturnCallback(function (string $endpoint) use (&$hotspotReads, $serverName, $poolName): array {
                if ($endpoint === '/rest/ip/hotspot') {
                    $hotspotReads++;

                    return $hotspotReads === 1 ? [] : [[
                        '.id' => '*1',
                        'name' => $serverName,
                        'interface' => 'bridge-lan',
                        'address-pool' => $poolName,
                        'profile' => 'default',
                        'disabled' => 'no',
                    ]];
                }

                return match ($endpoint) {
                    '/rest/ip/hotspot/profile' => [['name' => 'default']],
                    '/rest/interface' => [['name' => 'bridge-lan', 'type' => 'bridge']],
                    default => [],
                };
            });
        $client->expects($this->once())
            ->method('put')
            ->with('/rest/ip/hotspot', [
                'name' => $serverName,
                'interface' => 'bridge-lan',
                'address-pool' => $poolName,
                'profile' => 'default',
                'disabled' => 'no',
            ])
            ->willReturn([]);

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(5))->method('success');

        $checks = (new MikrotikRouterGateway($connections, $recorder))
            ->configureHotspotServer($router, $configuration, 'correlation-id');

        $this->assertSame(['passed', 'passed', 'passed', 'passed'], array_column($checks, 'status'));
    }

    public function test_it_updates_an_existing_managed_hotspot_server(): void
    {
        $router = new Router(['name' => 'Branch Router', 'identity' => 'JEDC-HQ']);
        $serverName = HotspotServerConfiguration::nameFromRouter($router);
        $poolName = HotspotServerConfiguration::addressPoolFromRouter($router);
        $hotspotReads = 0;
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(4))
            ->method('get')
            ->willReturnCallback(function (string $endpoint) use (&$hotspotReads, $serverName, $poolName): array {
                if ($endpoint === '/rest/ip/hotspot') {
                    $hotspotReads++;

                    return [[
                        '.id' => '*1',
                        'name' => $serverName,
                        'interface' => 'bridge-lan',
                        'address-pool' => $hotspotReads === 1 ? 'old-pool' : $poolName,
                        'profile' => 'default',
                        'disabled' => 'no',
                    ]];
                }

                return match ($endpoint) {
                    '/rest/ip/hotspot/profile' => [['name' => 'default']],
                    '/rest/interface' => [['name' => 'bridge-lan', 'type' => 'bridge']],
                    default => [],
                };
            });
        $client->expects($this->never())->method('put');
        $client->expects($this->once())
            ->method('patch')
            ->with('/rest/ip/hotspot/*1', $this->callback(function (array $payload) use ($poolName): bool {
                return $payload['address-pool'] === $poolName
                    && ! array_key_exists('comment', $payload);
            }))
            ->willReturn([]);

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->exactly(5))->method('success');

        $checks = (new MikrotikRouterGateway($connections, $recorder))->configureHotspotServer($router, [
            'server_name' => $serverName,
            'interface' => 'bridge-lan',
            'address_pool' => $poolName,
            'profile' => 'default',
        ], 'correlation-id');

        $this->assertSame(['passed', 'passed', 'passed', 'passed'], array_column($checks, 'status'));
    }

    public function test_it_refuses_an_unmanaged_hotspot_server_on_the_selected_interface(): void
    {
        $router = new Router(['name' => 'Branch Router', 'identity' => 'JEDC-HQ']);
        $serverName = HotspotServerConfiguration::nameFromRouter($router);
        $poolName = HotspotServerConfiguration::addressPoolFromRouter($router);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('get')
            ->with('/rest/ip/hotspot')
            ->willReturn([[
                '.id' => '*5',
                'name' => 'hotspot1',
                'interface' => 'bridge-lan',
                'address-pool' => 'default-dhcp',
                'profile' => 'default',
                'disabled' => 'no',
            ]]);
        $client->expects($this->never())->method('put');
        $client->expects($this->never())->method('patch');

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->once())->method('success');

        $this->expectException(RouterProbeException::class);

        (new MikrotikRouterGateway($connections, $recorder))->configureHotspotServer($router, [
            'server_name' => $serverName,
            'interface' => 'bridge-lan',
            'address_pool' => $poolName,
            'profile' => 'default',
        ], 'correlation-id');
    }

    public function test_it_marks_only_the_identity_named_hotspot_server_as_managed(): void
    {
        $router = new Router(['name' => 'Branch Router', 'identity' => 'JEDC-HQ']);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('get')
            ->with('/rest/ip/hotspot')
            ->willReturn([
                [
                    '.id' => '*2',
                    'name' => 'mmutech-hotspot',
                    'interface' => 'bridge-lan',
                    'address-pool' => 'default-dhcp',
                    'profile' => 'default',
                    'disabled' => 'no',
                ],
                [
                    '.id' => '*1',
                    'name' => 'JEDC-HQ',
                    'interface' => 'bridge-lan',
                    'address-pool' => 'JEDC-HQ',
                    'profile' => 'default',
                    'disabled' => 'yes',
                ],
            ]);

        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->with($router)->willReturn($client);

        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->once())->method('success');

        $servers = (new MikrotikRouterGateway($connections, $recorder))
            ->hotspotServers($router, 'correlation-id');

        $this->assertSame([
            [
                'id' => '*1',
                'name' => 'JEDC-HQ',
                'interface' => 'bridge-lan',
                'address_pool' => 'JEDC-HQ',
                'profile' => 'default',
                'disabled' => true,
                'managed' => true,
            ],
            [
                'id' => '*2',
                'name' => 'mmutech-hotspot',
                'interface' => 'bridge-lan',
                'address_pool' => 'default-dhcp',
                'profile' => 'default',
                'disabled' => false,
                'managed' => false,
            ],
        ], $servers);
    }
}
