<?php

namespace App\Services\Vouchers;

use App\Enums\ProvisioningStatus;
use App\Enums\RouterErrorCategory;
use App\Enums\RouterStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\AuditLogger;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mivo\LaravelMikrotikRos7\Services\HotspotManager;
use Mivo\MikrotikRos7\Client;
use Mivo\MikrotikRos7\Exceptions\MikrotikException;
use UnexpectedValueException;

class VoucherValiditySyncService
{
    public function __construct(
        private readonly RouterConnectionFactory $connections,
        private readonly RouterApiRecorder $recorder,
        private readonly AuditLogger $audit,
    ) {}

    public function sync(): void
    {
        $now = Carbon::now();

        Router::query()
            ->whereNotIn('status', [RouterStatus::Disabled->value, RouterStatus::Archived->value])
            ->whereHas('vouchers', fn (Builder $query): Builder => $this->eligibleVouchers($query, $now))
            ->orderBy('id')
            ->chunkById(100, function ($routers) use ($now): void {
                foreach ($routers as $router) {
                    $this->syncRouter($router, $now);
                }
            });
    }

    private function syncRouter(Router $router, Carbon $now): void
    {
        $correlationId = (string) Str::uuid();
        $started = hrtime(true);

        try {
            $client = $this->connections->make($router);
            $hotspot = new HotspotManager($client);
            $activeSessions = $hotspot->getActive();
        } catch (RouterProbeException|MikrotikException $exception) {
            $this->recorder->failure(
                $router,
                'GET',
                '/rest/ip/hotspot/active',
                $this->elapsedMs($started),
                RouterErrorCategory::Unknown,
                $exception->getMessage(),
                null,
                [],
                [],
                $correlationId,
            );
            Log::error('Unable to read active hotspot sessions for voucher validity sync.', [
                'router_id' => $router->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $this->recorder->success(
            $router,
            'GET',
            '/rest/ip/hotspot/active',
            $this->elapsedMs($started),
            200,
            [],
            ['count' => count($activeSessions)],
            $correlationId,
        );

        $sessionsByUsername = [];

        foreach ($activeSessions as $session) {
            $username = $session['user'] ?? null;

            if ($username !== null) {
                $sessionsByUsername[$username][] = $session;
            }
        }

        $vouchers = $this->eligibleVouchers(
            $router->vouchers()->getQuery(),
            $now,
        )->get();

        foreach ($vouchers as $voucher) {
            try {
                if ($voucher->first_used_at === null && isset($sessionsByUsername[$voucher->username])) {
                    $this->recordFirstUse($voucher, $now, $correlationId);
                }

                $voucher->refresh();

                if ($voucher->expires_at !== null && $voucher->expires_at->lte($now)) {
                    $this->expireVoucher(
                        $voucher,
                        $sessionsByUsername[$voucher->username] ?? [],
                        $client,
                        $hotspot,
                        $now,
                        $correlationId,
                    );
                }
            } catch (MikrotikException|UnexpectedValueException $exception) {
                Log::error('Unable to synchronize voucher validity.', [
                    'router_id' => $router->getKey(),
                    'voucher_id' => $voucher->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function recordFirstUse(Voucher $voucher, Carbon $now, string $correlationId): void
    {
        $firstUsedAt = $now->copy();
        $expiresAt = $voucher->validity_seconds === null
            ? null
            : $firstUsedAt->copy()->addSeconds($voucher->validity_seconds);

        $updated = Voucher::query()
            ->whereKey($voucher->getKey())
            ->whereNull('first_used_at')
            ->where('provisioning_status', ProvisioningStatus::Provisioned->value)
            ->whereIn('lifecycle_status', [
                VoucherLifecycleStatus::Active->value,
                VoucherLifecycleStatus::Pending->value,
            ])
            ->update([
                'first_used_at' => $firstUsedAt,
                'expires_at' => $expiresAt,
                'last_synced_at' => $firstUsedAt,
            ]);

        if ($updated === 1) {
            $voucher->refresh();
            $this->audit->record('voucher.first_used', $voucher, correlationId: $correlationId);
        }
    }

    /**
     * @param  array<int, array<string, string>>  $activeSessions
     */
    private function expireVoucher(
        Voucher $voucher,
        array $activeSessions,
        Client $client,
        HotspotManager $hotspot,
        Carbon $now,
        string $correlationId,
    ): void {
        foreach ($activeSessions as $session) {
            $sessionId = $session['.id'] ?? null;

            if (! is_string($sessionId) || ! preg_match('/\A[\w.*-]+\z/D', $sessionId)) {
                throw new UnexpectedValueException('An active hotspot session has an invalid RouterOS ID.');
            }

            $started = hrtime(true);

            try {
                $client->delete('/rest/ip/hotspot/active/'.$sessionId);
            } catch (MikrotikException $exception) {
                $this->recordApiFailure(
                    $voucher->router,
                    'DELETE',
                    '/rest/ip/hotspot/active/'.$sessionId,
                    $started,
                    $exception,
                    $voucher->username,
                    $correlationId,
                );

                throw $exception;
            }

            $this->recorder->success(
                $voucher->router,
                'DELETE',
                '/rest/ip/hotspot/active/'.$sessionId,
                $this->elapsedMs($started),
                200,
                ['name' => $voucher->username],
                [],
                $correlationId,
            );
        }

        $started = hrtime(true);

        try {
            $hotspot->removeUser($voucher->username);
        } catch (MikrotikException $exception) {
            $this->recordApiFailure(
                $voucher->router,
                'DELETE',
                '/rest/ip/hotspot/user',
                $started,
                $exception,
                $voucher->username,
                $correlationId,
            );

            throw $exception;
        }

        $this->recorder->success(
            $voucher->router,
            'DELETE',
            '/rest/ip/hotspot/user',
            $this->elapsedMs($started),
            200,
            ['name' => $voucher->username],
            [],
            $correlationId,
        );

        $updated = Voucher::query()
            ->whereKey($voucher->getKey())
            ->where('provisioning_status', ProvisioningStatus::Provisioned->value)
            ->whereIn('lifecycle_status', [
                VoucherLifecycleStatus::Active->value,
                VoucherLifecycleStatus::Pending->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->update([
                'lifecycle_status' => VoucherLifecycleStatus::Expired,
                'provisioning_status' => ProvisioningStatus::Removed,
                'last_synced_at' => $now,
            ]);

        if ($updated === 1) {
            $voucher->refresh();
            $this->audit->record('voucher.expired', $voucher, correlationId: $correlationId);
        }
    }

    private function recordApiFailure(
        Router $router,
        string $method,
        string $endpoint,
        int $started,
        MikrotikException $exception,
        string $username,
        string $correlationId,
    ): void {
        $this->recorder->failure(
            $router,
            $method,
            $endpoint,
            $this->elapsedMs($started),
            RouterErrorCategory::Unknown,
            $exception->getMessage(),
            null,
            ['name' => $username],
            [],
            $correlationId,
        );
    }

    private function eligibleVouchers(Builder $query, Carbon $now): Builder
    {
        return $query
            ->where('provisioning_status', ProvisioningStatus::Provisioned->value)
            ->whereIn('lifecycle_status', [
                VoucherLifecycleStatus::Active->value,
                VoucherLifecycleStatus::Pending->value,
            ])
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('first_used_at')
                    ->orWhere(function (Builder $query) use ($now): void {
                        $query->whereNotNull('expires_at')
                            ->where('expires_at', '<=', $now);
                    });
            });
    }

    private function elapsedMs(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
