<?php

namespace Tests\Unit;

use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Services\Mikrotik\MikrotikRouterGateway;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use Mivo\MikrotikRos7\Client;
use PHPUnit\Framework\TestCase;

class MikrotikRouterGatewayTest extends TestCase
{
    public function test_it_writes_the_registered_name_as_the_routeros_identity(): void
    {
        $router = new Router(['name' => 'Branch Router']);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('put')
            ->with('/rest/system/identity', ['name' => 'Branch Router'])
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
                'PUT',
                '/rest/system/identity',
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
}
