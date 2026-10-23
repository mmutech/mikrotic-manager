<?php

namespace App\Services\Vouchers;

use App\Enums\ProvisioningStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\AuditLogger;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use Illuminate\Support\Carbon;
use Mivo\LaravelMikrotikRos7\Services\HotspotManager;
use Throwable;

class VoucherProvisioningService
{
    public function __construct(
        private readonly RouterConnectionFactory $connections,
        private readonly RouterApiRecorder $recorder,
        private readonly AuditLogger $audit,
    ) {}

    public function provision(Voucher $voucher, ?string $correlationId = null): void
    {
        $correlationId ??= (string) \Illuminate\Support\Str::uuid();
        $voucher->loadMissing(['router', 'plan']);

        if ($voucher->provisioning_status === ProvisioningStatus::Provisioning) {
            return;
        }

        $voucher->update([
            'provisioning_status' => ProvisioningStatus::Provisioning,
        ]);

        $router = $voucher->router;
        $started = hrtime(true);

        try {
            $client = $this->connections->make($router);
            $hotspot = new HotspotManager($client);

            $data = [
                'name' => $voucher->username,
                'password' => $voucher->password,
                'profile' => $voucher->plan?->code ?? 'default',
            ];

            if ($voucher->data_allowance_bytes !== null) {
                $data['limit-bytes-total'] = (string) $voucher->data_allowance_bytes;
            }

            if ($voucher->time_allowance_seconds !== null) {
                $minutes = (int) ceil($voucher->time_allowance_seconds / 60);
                if ($minutes > 0) {
                    $data['limit-uptime'] = $minutes.'m';
                }
            }

            $hotspot->addUser($data);
            $latency = $this->elapsedMs($started);

            $this->recorder->success(
                $router,
                'PUT',
                '/rest/ip/hotspot/user',
                $latency,
                200,
                ['name' => $voucher->username],
                ['profile' => $data['profile'] ?? null],
                $correlationId
            );

            $voucher->update([
                'provisioning_status' => ProvisioningStatus::Provisioned,
                'provisioned_at' => Carbon::now(),
                'last_synced_at' => Carbon::now(),
            ]);

            if ($voucher->lifecycle_status === VoucherLifecycleStatus::Pending) {
                $voucher->update(['lifecycle_status' => VoucherLifecycleStatus::Active]);
            }

            $this->audit->record('voucher.provisioned', $voucher, actor: auth()->user(), correlationId: $correlationId);
        } catch (Throwable $e) {
            $this->recorder->failure(
                $router,
                'PUT',
                '/rest/ip/hotspot/user',
                $this->elapsedMs($started),
                \App\Enums\RouterErrorCategory::Unknown,
                $e->getMessage(),
                null,
                ['name' => $voucher->username],
                [],
                $correlationId
            );

            $voucher->update([
                'provisioning_status' => ProvisioningStatus::Failed,
            ]);

            $this->audit->record('voucher.provision_failed', $voucher, metadata: ['error' => $e->getMessage()], actor: auth()->user(), correlationId: $correlationId);
            throw $e;
        }
    }

    public function revoke(Voucher $voucher, ?string $correlationId = null): void
    {
        $correlationId ??= (string) \Illuminate\Support\Str::uuid();
        $voucher->loadMissing(['router']);

        $started = hrtime(true);
        try {
            $client = $this->connections->make($voucher->router);
            $hotspot = new HotspotManager($client);
            $hotspot->removeUser($voucher->username);
            $latency = $this->elapsedMs($started);

            $this->recorder->success(
                $voucher->router,
                'DELETE',
                '/rest/ip/hotspot/user',
                $latency,
                200,
                ['name' => $voucher->username],
                [],
                $correlationId
            );

            $voucher->update([
                'provisioning_status' => ProvisioningStatus::Removed,
                'lifecycle_status' => VoucherLifecycleStatus::Revoked,
                'revoked_at' => Carbon::now(),
                'last_synced_at' => Carbon::now(),
            ]);

            $this->audit->record('voucher.revoked', $voucher, actor: auth()->user(), correlationId: $correlationId);
        } catch (Throwable $e) {
            $this->recorder->failure(
                $voucher->router,
                'DELETE',
                '/rest/ip/hotspot/user',
                $this->elapsedMs($started),
                \App\Enums\RouterErrorCategory::Unknown,
                $e->getMessage(),
                null,
                ['name' => $voucher->username],
                [],
                $correlationId
            );
            throw $e;
        }
    }

    private function elapsedMs(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
