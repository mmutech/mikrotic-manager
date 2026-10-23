<?php

namespace App\Services;

use App\Enums\RouterStatus;
use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Models\User;
use App\Support\RouterHostPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouterRegistryService
{
    public function __construct(
        private readonly RouterHostPolicy $hostPolicy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Router
    {
        $data = $this->normalise($data);
        $this->assertHostAndPort($data);
        $this->assertUniqueEndpoint($data);

        $router = DB::transaction(function () use ($data, $actor): Router {
            $router = new Router($data);
            $router->status = ($data['monitoring_enabled'] ?? true)
                ? RouterStatus::Pending
                : RouterStatus::Disabled;
            $router->created_by = $actor->getKey();
            $router->api_password_rotated_at = now();
            $router->save();

            // The creator always receives explicit access, even when they are
            // not a super administrator. This keeps router visibility scoped.
            $router->users()->syncWithoutDetaching([
                $actor->getKey() => ['granted_by' => $actor->getKey()],
            ]);

            $this->audit->record(
                'router.created',
                $router,
                after: $router->auditSnapshot(),
                metadata: ['host' => $router->host, 'port' => $router->rest_port],
                actor: $actor,
            );

            return $router;
        });

        return $router;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Router $router, array $data, User $actor): Router
    {
        $data = $this->normalise($data);
        $before = $router->auditSnapshot();

        if (array_key_exists('api_password', $data) && blank($data['api_password'])) {
            unset($data['api_password']);
        }

        $passwordChanged = array_key_exists('api_password', $data);

        $merged = array_merge([
            'host' => $router->host,
            'rest_port' => $router->rest_port,
        ], $data);

        $this->assertHostAndPort($merged);
        $this->assertUniqueEndpoint($merged, $router->getKey());

        return DB::transaction(function () use ($router, $data, $actor, $before, $passwordChanged, $merged): Router {
            $endpointChanged = $merged['host'] !== $router->host
                || (int) $merged['rest_port'] !== (int) $router->rest_port;

            $router->fill($data);

            if ($passwordChanged) {
                $router->api_password_rotated_at = now();
            }

            if ($endpointChanged) {
                // Identity and health data belong to the previous endpoint.
                $router->identity = null;
                $router->ros_version = null;
                $router->board_name = null;
                $router->latency_ms = null;
                $router->last_checked_at = null;
                $router->last_seen_at = null;
                $router->last_error_category = null;
                $router->last_error_message = null;
                $router->consecutive_failures = 0;
                $router->status = $router->monitoring_enabled ? RouterStatus::Pending : RouterStatus::Disabled;
            }

            $router->save();

            $this->audit->record(
                'router.updated',
                $router,
                before: $before,
                after: $router->auditSnapshot(),
                metadata: [
                    'password_rotated' => $passwordChanged,
                    'endpoint_changed' => $endpointChanged,
                ],
                actor: $actor,
            );

            return $router;
        });
    }

    public function setMonitoring(Router $router, bool $enabled, User $actor): Router
    {
        $before = $router->auditSnapshot();

        return DB::transaction(function () use ($router, $enabled, $actor, $before): Router {
            $router->monitoring_enabled = $enabled;

            if (! $enabled && $router->status === RouterStatus::Online) {
                $router->status = RouterStatus::Disabled;
            }

            if ($enabled && $router->status === RouterStatus::Disabled) {
                $router->status = RouterStatus::Pending;
            }

            $router->save();

            $this->audit->record(
                'router.monitoring_changed',
                $router,
                before: $before,
                after: $router->auditSnapshot(),
                actor: $actor,
            );

            return $router;
        });
    }

    public function archive(Router $router, User $actor): void
    {
        $before = $router->auditSnapshot();

        DB::transaction(function () use ($router, $actor, $before): void {
            $router->status = RouterStatus::Archived;
            $router->monitoring_enabled = false;
            $router->save();
            $router->delete();

            $this->audit->record(
                'router.archived',
                $router,
                before: $before,
                after: $router->auditSnapshot(),
                actor: $actor,
            );
        });
    }

    public function grantAccess(Router $router, User $user, User $actor): void
    {
        $router->users()->syncWithoutDetaching([
            $user->getKey() => ['granted_by' => $actor->getKey()],
        ]);

        $this->audit->record(
            'router.access_granted',
            $router,
            metadata: ['user_id' => $user->getKey(), 'email' => $user->email],
            actor: $actor,
        );
    }

    public function revokeAccess(Router $router, User $user, User $actor): void
    {
        $router->users()->detach($user->getKey());

        $this->audit->record(
            'router.access_revoked',
            $router,
            metadata: ['user_id' => $user->getKey(), 'email' => $user->email],
            actor: $actor,
        );
    }

    /** @param array<string, mixed> $data */
    private function assertHostAndPort(array $data): void
    {
        try {
            $this->hostPolicy->assertAllowed((string) ($data['host'] ?? ''));
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['host' => $exception->getMessage()]);
        }

        $port = (int) ($data['rest_port'] ?? 0);

        if (! in_array($port, (array) config('mikrotik.allowed_ports', [443, 8729]), true)) {
            throw ValidationException::withMessages([
                'rest_port' => 'Use an approved RouterOS REST port (80, 443, or 8729).',
            ]);
        }

        if ($port === 80 && ! config('mikrotik.allow_http', false)) {
            throw ValidationException::withMessages([
                'rest_port' => 'Plain HTTP is disabled. Use HTTPS on port 443 or 8729.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertUniqueEndpoint(array $data, ?int $ignoreId = null): void
    {
        $exists = Router::withTrashed()
            ->where('host', $data['host'])
            ->where('rest_port', (int) $data['rest_port'])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'host' => 'That host and port are already registered to another router.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function normalise(array $data): array
    {
        $allowed = [
            'name', 'location', 'site', 'host', 'rest_port', 'api_username',
            'api_password', 'tls_verify', 'monitoring_enabled', 'notes',
        ];

        $data = array_intersect_key($data, array_flip($allowed));

        foreach (['name', 'location', 'site', 'host', 'api_username', 'notes'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        if (array_key_exists('rest_port', $data)) {
            $data['rest_port'] = (int) $data['rest_port'];
        }

        return $data;
    }
}
