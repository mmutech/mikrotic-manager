<?php

namespace App\Services\Vouchers;

use App\Contracts\RouterGateway;
use App\Enums\ProvisioningStatus;
use App\Enums\RouterErrorCategory;
use App\Enums\VoucherLifecycleStatus;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Models\RouterPlanProfile;
use App\Models\Voucher;
use App\Services\AuditLogger;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use App\Support\HotspotRateLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mivo\MikrotikRos7\Client;
use Throwable;
use UnexpectedValueException;

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
        $routerCallAttempted = false;
        $operation = [
            'method' => 'PUT',
            'endpoint' => '/rest/ip/hotspot/user',
            'started' => hrtime(true),
        ];

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

            if ($voucher->time_allowance_seconds !== null && $voucher->time_allowance_seconds < 0) {
                throw RouterProbeException::configuration('A voucher connected-time allowance cannot be negative.');
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

            // RouterOS uses 0s to mean no connected-time limit. Sending it
            // explicitly clears any limit left on a reprovisioned user.
            if ($voucher->time_allowance_seconds !== null) {
                $data['limit-uptime'] = $voucher->time_allowance_seconds.'s';
            }

            $client = $this->connections->make($router);
            $routerCallAttempted = true;
            $existingUser = $this->findHotspotUser(
                $client,
                $router,
                $voucher->username,
                $correlationId,
                $operation,
            );

            if ($existingUser !== null) {
                $userId = $this->routerOsId($existingUser['.id'] ?? null);
                $endpoint = '/rest/ip/hotspot/user/'.$userId;
                $data['disabled'] = 'no';

                $operation = [
                    'method' => 'PATCH',
                    'endpoint' => $endpoint,
                    'started' => hrtime(true),
                ];
                $client->patch($endpoint, $data);

                $this->recorder->success(
                    $router,
                    'PATCH',
                    $endpoint,
                    $this->elapsedMs($operation['started']),
                    200,
                    ['name' => $voucher->username],
                    ['disabled' => 'no'],
                    $correlationId
                );
            } else {
                $operation = [
                    'method' => 'PUT',
                    'endpoint' => '/rest/ip/hotspot/user',
                    'started' => hrtime(true),
                ];
                $client->put('/rest/ip/hotspot/user', $data);

                $this->recorder->success(
                    $router,
                    'PUT',
                    '/rest/ip/hotspot/user',
                    $this->elapsedMs($operation['started']),
                    200,
                    ['name' => $voucher->username],
                    ['profile' => $data['profile'] ?? null],
                    $correlationId
                );
            }

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
            if ($routerCallAttempted) {
                $this->recorder->failure(
                    $router,
                    $operation['method'],
                    $operation['endpoint'],
                    $this->elapsedMs($operation['started']),
                    $this->errorCategory($e),
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

        $router = $voucher->router;
        $username = $voucher->username;
        $sessionsDisconnected = 0;
        $userDisabled = false;
        $operation = [
            'method' => 'GET',
            'endpoint' => '/rest/ip/hotspot/user',
            'started' => hrtime(true),
        ];

        try {
            $client = $this->connections->make($router);

            $user = $this->findHotspotUser(
                $client,
                $router,
                $username,
                $correlationId,
                $operation,
            );

            if ($user !== null) {
                $userId = $this->routerOsId($user['.id'] ?? null);
                $endpoint = '/rest/ip/hotspot/user/'.$userId;

                $operation = [
                    'method' => 'PATCH',
                    'endpoint' => $endpoint,
                    'started' => hrtime(true),
                ];
                $client->patch($endpoint, ['disabled' => 'yes']);

                $this->recorder->success(
                    $router,
                    'PATCH',
                    $endpoint,
                    $this->elapsedMs($operation['started']),
                    200,
                    ['name' => $username],
                    ['disabled' => 'yes'],
                    $correlationId
                );

                $userDisabled = true;
            }

            $activeSessions = $this->activeHotspotSessions(
                $client,
                $router,
                $correlationId,
                $operation,
            );

            foreach ($activeSessions as $session) {
                if (! $this->isSessionFor($session, $username)) {
                    continue;
                }

                $sessionId = $this->routerOsId($session['.id'] ?? null);
                $endpoint = '/rest/ip/hotspot/active/'.$sessionId;

                $operation = [
                    'method' => 'DELETE',
                    'endpoint' => $endpoint,
                    'started' => hrtime(true),
                ];
                $client->delete($endpoint);

                $this->recorder->success(
                    $router,
                    'DELETE',
                    $endpoint,
                    $this->elapsedMs($operation['started']),
                    200,
                    ['name' => $username],
                    [],
                    $correlationId
                );

                $sessionsDisconnected++;
            }

            $verifiedUser = $this->findHotspotUser(
                $client,
                $router,
                $username,
                $correlationId,
                $operation,
            );
            $verifiedSessions = $this->activeHotspotSessions(
                $client,
                $router,
                $correlationId,
                $operation,
            );

            foreach ($verifiedSessions as $session) {
                if ($this->isSessionFor($session, $username)) {
                    throw RouterProbeException::configuration(
                        'Revocation verification failed: the HotSpot user still has an active session.'
                    );
                }
            }

            if ($verifiedUser !== null && ! $this->routerOsBoolean($verifiedUser['disabled'] ?? false)) {
                throw RouterProbeException::configuration(
                    'Revocation verification failed: the HotSpot user is still enabled on the router.'
                );
            }

            $now = Carbon::now();

            $voucher->update([
                'provisioning_status' => ProvisioningStatus::Disabled,
                'lifecycle_status' => VoucherLifecycleStatus::Revoked,
                'revoked_at' => $now,
                'revoked_by' => auth()->id(),
                'last_synced_at' => $now,
            ]);

            $this->audit->record(
                'voucher.revoked',
                $voucher,
                metadata: [
                    'user_disabled' => $userDisabled,
                    'sessions_disconnected' => $sessionsDisconnected,
                ],
                actor: auth()->user(),
                correlationId: $correlationId
            );
        } catch (Throwable $e) {
            $this->recorder->failure(
                $router,
                $operation['method'],
                $operation['endpoint'],
                $this->elapsedMs($operation['started']),
                $this->errorCategory($e),
                $e->getMessage(),
                null,
                ['name' => $username],
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

    /**
     * @param  array{method: string, endpoint: string, started: int}  $operation
     * @return array<string, mixed>|null
     */
    private function findHotspotUser(
        Client $client,
        Router $router,
        string $username,
        string $correlationId,
        array &$operation,
    ): ?array {
        $operation = [
            'method' => 'GET',
            'endpoint' => '/rest/ip/hotspot/user',
            'started' => hrtime(true),
        ];

        $users = $client->get('/rest/ip/hotspot/user', ['name' => $username]);

        $this->recorder->success(
            $router,
            'GET',
            '/rest/ip/hotspot/user',
            $this->elapsedMs($operation['started']),
            200,
            ['name' => $username],
            ['found' => $users !== []],
            $correlationId
        );

        return $users[0] ?? null;
    }

    /**
     * @param  array{method: string, endpoint: string, started: int}  $operation
     * @return array<int, array<string, mixed>>
     */
    private function activeHotspotSessions(
        Client $client,
        Router $router,
        string $correlationId,
        array &$operation,
    ): array {
        $operation = [
            'method' => 'GET',
            'endpoint' => '/rest/ip/hotspot/active',
            'started' => hrtime(true),
        ];

        $sessions = $client->get('/rest/ip/hotspot/active');

        $this->recorder->success(
            $router,
            'GET',
            '/rest/ip/hotspot/active',
            $this->elapsedMs($operation['started']),
            200,
            [],
            ['count' => count($sessions)],
            $correlationId
        );

        return $sessions;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function isSessionFor(array $session, string $username): bool
    {
        return ($session['user'] ?? null) === $username
            || ($session['username'] ?? null) === $username;
    }

    private function routerOsId(mixed $id): string
    {
        if (! is_string($id) || preg_match('/\A[\w.*-]+\z/D', $id) !== 1) {
            throw new UnexpectedValueException('A RouterOS entry has an invalid .id.');
        }

        return $id;
    }

    private function routerOsBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 'true', 'yes'], true);
    }

    private function errorCategory(Throwable $exception): RouterErrorCategory
    {
        return $exception instanceof RouterProbeException
            ? $exception->category
            : RouterErrorCategory::Unknown;
    }

    private function elapsedMs(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
