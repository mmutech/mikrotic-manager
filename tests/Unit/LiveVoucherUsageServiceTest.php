<?php

namespace Tests\Unit;

use App\Contracts\RouterGateway;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\Vouchers\LiveVoucherUsageService;
use App\Support\CorrelationContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LiveVoucherUsageServiceTest extends TestCase
{
    public function test_it_matches_live_sessions_to_vouchers_and_reuses_one_read_per_router(): void
    {
        $router = new Router(['id' => 1, 'name' => 'HQ']);
        $connected = (new Voucher)->forceFill(['id' => 1, 'username' => 'VOUCHER-A', 'router_id' => 1]);
        $idle = (new Voucher)->forceFill(['id' => 2, 'username' => 'VOUCHER-B', 'router_id' => 1]);
        $connected->setRelation('router', $router);
        $idle->setRelation('router', $router);

        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('activeHotspotSessions')
            ->with($router, $this->isType('string'))
            ->willReturn([
                [
                    'id' => '*1',
                    'username' => 'VOUCHER-A',
                    'connected_seconds' => 3661,
                    'total_bytes' => 1536,
                ],
            ]);

        $usage = (new LiveVoucherUsageService($gateway, new CorrelationContext))
            ->forVouchers(collect([$connected, $idle]));

        $this->assertSame('connected', $usage->get(1)['status']);
        $this->assertSame('1h 1m 1s', $usage->get(1)['duration']);
        $this->assertSame('1.5 KB', $usage->get(1)['consumption']);
        $this->assertSame('idle', $usage->get(2)['status']);
        $this->assertSame('Not connected', $usage->get(2)['duration']);
    }

    public function test_it_reports_live_reads_as_unavailable_when_the_router_fails(): void
    {
        $router = new Router(['id' => 1, 'name' => 'HQ']);
        $voucher = (new Voucher)->forceFill(['id' => 1, 'username' => 'VOUCHER-A', 'router_id' => 1]);
        $voucher->setRelation('router', $router);

        $gateway = $this->createMock(RouterGateway::class);
        $gateway->method('activeHotspotSessions')
            ->willThrowException(new RuntimeException('Router offline'));

        $usage = (new LiveVoucherUsageService($gateway, new CorrelationContext))
            ->forVoucher($voucher);

        $this->assertSame('unavailable', $usage['status']);
        $this->assertSame('Live read unavailable', $usage['duration']);
        $this->assertSame('Live read unavailable', $usage['consumption']);
    }
}
