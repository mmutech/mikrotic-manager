<?php

namespace App\Services\Vouchers;

use App\Contracts\RouterGateway;
use App\Models\Router;
use App\Models\Voucher;
use App\Support\CorrelationContext;
use Illuminate\Support\Collection;
use Throwable;

final class LiveVoucherUsageService
{
    public function __construct(
        private readonly RouterGateway $gateway,
        private readonly CorrelationContext $correlation,
    ) {}

    /**
     * Read active RouterOS sessions once per router and map them to the vouchers.
     *
     * @param  Collection<int, Voucher>  $vouchers
     * @return Collection<int|string, array{status: string, duration: string, consumption: string}>
     */
    public function forVouchers(Collection $vouchers): Collection
    {
        $usage = collect();

        foreach ($vouchers->groupBy(fn (Voucher $voucher): string => (string) $voucher->router_id) as $routerVouchers) {
            $router = $routerVouchers->first()?->router;
            $sessions = $router instanceof Router ? $this->readSessions($router) : null;

            foreach ($routerVouchers as $voucher) {
                $usage->put($voucher->getKey(), $this->usageFor($voucher, $sessions));
            }
        }

        return $usage;
    }

    /**
     * @return array{status: string, duration: string, consumption: string}
     */
    public function forVoucher(Voucher $voucher): array
    {
        $router = $voucher->router;
        $sessions = $router instanceof Router ? $this->readSessions($router) : null;

        return $this->usageFor($voucher, $sessions);
    }

    /**
     * @return Collection<int, array<string, mixed>>|null
     */
    private function readSessions(Router $router): ?Collection
    {
        try {
            return collect($this->gateway->activeHotspotSessions($router, $this->correlation->id()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>|null  $sessions
     * @return array{status: string, duration: string, consumption: string}
     */
    private function usageFor(Voucher $voucher, ?Collection $sessions): array
    {
        if ($sessions === null) {
            return [
                'status' => 'unavailable',
                'duration' => 'Live read unavailable',
                'consumption' => 'Live read unavailable',
            ];
        }

        $session = $sessions->first(
            fn (array $row): bool => strcasecmp((string) ($row['username'] ?? ''), (string) $voucher->username) === 0,
        );

        if ($session === null) {
            return [
                'status' => 'idle',
                'duration' => 'Not connected',
                'consumption' => 'Not connected',
            ];
        }

        return [
            'status' => 'connected',
            'duration' => Voucher::formatDuration((int) ($session['connected_seconds'] ?? 0)),
            'consumption' => Voucher::formatBytes((int) ($session['total_bytes'] ?? 0)),
        ];
    }
}
