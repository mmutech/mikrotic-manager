<?php

namespace App\Services\Vouchers;

use App\Contracts\RouterGateway;
use App\Enums\ProvisioningStatus;
use App\Enums\RouterErrorCategory;
use App\Enums\VoucherLifecycleStatus;
use App\Exceptions\RouterProbeException;
use App\Models\RouterPlanProfile;
use App\Models\Voucher;
use App\Services\AuditLogger;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use App\Support\HotspotRateLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mivo\LaravelMikrotikRos7\Services\HotspotManager;
use Throwable;

class VoucherProvisioningService
{
    public function __construct(
        private readonly RouterConnectionFactory $connections,
        private readonly RouterApiRecorder $recorder,
        private readonly AuditLogger $audit,
        private readonly RouterGateway $gateway,
    ) {}

    public function provision(Voucher $voucher, ?string $correlationId = null): void
    {
        $correlationId ??= (string) Str::uuid();
        $voucher->loadMissing(['router']);

        $claimed = Voucher::query()
            ->whereKey($voucher->getKey())
            ->whereIn('provisioning_status', [
                ProvisioningStatus::Pending->value,
                ProvisioningStatus::Queued->value,
                ProvisioningStatus::Failed->value,
            ])
            ->update([
                'provisioning_status' => ProvisioningStatus::Provisioning,
                'provisioning_error' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $voucher->refresh();

        $router = $voucher->router;
        $putStarted = null;

        try {
            if (! $voucher->canBeProvisioned()) {
                throw RouterProbeException::configuration(
                    'This voucher is no longer eligible for provisioning because it is expired, exhausted, or not active.'
                );
            }

            $hotspotProfile = $voucher->hotspot_profile;
            if (blank($hotspotProfile)) {
                $hotspotProfile = RouterPlanProfile::query()
                    ->where('router_id', $voucher->router_id)
                    ->where('plan_id', $voucher->plan_id)
                    ->value('hotspot_profile');
            }

            if (! is_string($hotspotProfile) || blank($hotspotProfile)) {
                throw RouterProbeException::configuration(
                    'The voucher has no mapped RouterOS HotSpot user profile. Map its plan to a profile on this router, then retry.'
                );
            }

            if ($voucher->hotspot_profile !== $hotspotProfile) {
                $voucher->update(['hotspot_profile' => $hotspotProfile]);
            }

            if ($voucher->data_allowance_bytes !== null && $voucher->data_allowance_bytes <= 0) {
                throw RouterProbeException::configuration('A finite voucher data allowance must be greater than zero bytes.');
            }

            if ($voucher->time_allowance_seconds !== null && $voucher->time_allowance_seconds <= 0) {
                throw RouterProbeException::configuration('A voucher connected-time allowance must be greater than zero seconds.');
            }

            if (($voucher->upload_speed_kbps === null) !== ($voucher->download_speed_kbps === null)) {
                throw RouterProbeException::configuration('The voucher must have both upload and download limits, or neither.');
            }

            $routerProfiles = $this->gateway->hotspotUserProfiles($router, $correlationId);
            $routerProfile = collect($routerProfiles)
                ->firstWhere('name', $hotspotProfile);

            if ($routerProfile === null) {
                throw RouterProbeException::configuration(
                    "RouterOS HotSpot user profile {$hotspotProfile} does not exist on this router."
                );
            }

            try {
                $profileSpeeds = HotspotRateLimit::speedsKbps($routerProfile['rate_limit']);
            } catch (InvalidArgumentException $exception) {
                throw RouterProbeException::configuration(
                    "RouterOS HotSpot user profile {$hotspotProfile} has an unsupported rate limit: {$exception->getMessage()}"
                );
            }

            $voucherSpeeds = $voucher->upload_speed_kbps === null
                ? null
                : [
                    'upload' => $voucher->upload_speed_kbps,
                    'download' => $voucher->download_speed_kbps,
                ];

            // Legacy vouchers predate the snapshot columns. Use the mapped
            // RouterOS profile as the authoritative source and backfill the
            // snapshot before creating the user.
            if ($voucherSpeeds === null && $profileSpeeds !== null) {
                $voucher->update([
                    'upload_speed_kbps' => $profileSpeeds['upload'],
                    'download_speed_kbps' => $profileSpeeds['download'],
                ]);
                $voucherSpeeds = $profileSpeeds;
            }

            if ($profileSpeeds !== $voucherSpeeds) {
                $actualRateLimit = filled($routerProfile['rate_limit'])
                    ? $routerProfile['rate_limit']
                    : 'no rate limit';
                $expectedRateLimit = $voucherSpeeds === null
                    ? 'no rate limit'
                    : $voucherSpeeds['upload'].'k/'.$voucherSpeeds['download'].'k';

                throw RouterProbeException::configuration(
                    "RouterOS HotSpot user profile {$hotspotProfile} does not match the plan: its rate limit is {$actualRateLimit}, but the plan requires {$expectedRateLimit}."
                );
            }

            $data = [
                'name' => $voucher->username,
                'password' => $voucher->username,
                'profile' => $hotspotProfile,
            ];

            if ($voucher->password !== $voucher->username) {
                $voucher->update(['password' => $voucher->username]);
            }

            if ($voucher->data_allowance_bytes !== null) {
                $data['limit-bytes-total'] = (string) $voucher->data_allowance_bytes;
            }

            if ($voucher->time_allowance_seconds !== null) {
                $data['limit-uptime'] = $voucher->time_allowance_seconds.'s';
            }

            $client = $this->connections->make($router);
            $hotspot = new HotspotManager($client);
            $putStarted = hrtime(true);
            $hotspot->addUser($data);
            $latency = $this->elapsedMs($putStarted);

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
            if ($putStarted !== null) {
                $this->recorder->failure(
                    $router,
                    'PUT',
                    '/rest/ip/hotspot/user',
                    $this->elapsedMs($putStarted),
                    $e instanceof RouterProbeException ? $e->category : RouterErrorCategory::Unknown,
                    $e->getMessage(),
                    null,
                    ['name' => $voucher->username],
                    [],
                    $correlationId
                );
            }

            $voucher->update([
                'provisioning_status' => ProvisioningStatus::Failed,
                'provisioning_error' => $e->getMessage(),
            ]);

            $this->audit->record('voucher.provision_failed', $voucher, metadata: ['error' => $e->getMessage()], actor: auth()->user(), correlationId: $correlationId);
            throw $e;
        }
    }

    public function revoke(Voucher $voucher, ?string $correlationId = null): void
    {
        $correlationId ??= (string) Str::uuid();
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
                RouterErrorCategory::Unknown,
                $e->getMessage(),
                null,
                ['name' => $voucher->username],
                [],
                $correlationId
            );

            $voucher->update([
                'provisioning_status' => ProvisioningStatus::RevocationFailed,
                'provisioning_error' => $e->getMessage(),
            ]);

            $this->audit->record(
                'voucher.revoke_failed',
                $voucher,
                metadata: ['error' => $e->getMessage()],
                actor: auth()->user(),
                correlationId: $correlationId
            );

            throw $e;
        }
    }

    private function elapsedMs(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
