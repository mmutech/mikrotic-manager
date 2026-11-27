<?php

namespace App\Services\Api;

use App\Contracts\RouterGateway;
use App\Enums\ProvisioningStatus;
use App\Exceptions\RouterProbeException;
use App\Models\Plan;
use App\Models\Router;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CorrelationContext;
use App\Support\HotspotRateLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PlanService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RouterGateway $gateway,
        private readonly CorrelationContext $correlation,
    ) {}

    /**
     * Read-only HotSpot profile discovery. This never queues a RouterJob.
     *
     * @return array<int, array{name: string, rate_limit: ?string, shared_users: string, upload_speed_kbps: ?int, download_speed_kbps: ?int}>
     */
    public function hotspotProfiles(Router $router): array
    {
        $profiles = $this->readProfiles($router);

        return array_map(static function (array $profile): array {
            $speeds = null;

            try {
                $speeds = HotspotRateLimit::speedsKbps($profile['rate_limit'] ?? null);
            } catch (InvalidArgumentException) {
                // Keep an unparsable RouterOS rate visible without blocking discovery.
            }

            return [
                'name' => (string) ($profile['name'] ?? ''),
                'rate_limit' => $profile['rate_limit'] ?? null,
                'shared_users' => (string) ($profile['shared_users'] ?? ''),
                'upload_speed_kbps' => $speeds['upload'] ?? null,
                'download_speed_kbps' => $speeds['download'] ?? null,
            ];
        }, $profiles);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(User $actor, array $data): Plan
    {
        $router = $this->resolveRouter($actor, $data['router_id']);
        $profile = $this->validatedProfile($router, (string) $data['hotspot_profile']);

        $plan = DB::transaction(function () use ($actor, $data, $profile, $router): Plan {
            $plan = Plan::create([
                'router_id' => $router->getKey(),
                'name' => $data['name'],
                'code' => $this->generateCode(),
                'description' => filled($data['description'] ?? null) ? $data['description'] : null,
                'price' => $data['price'],
                'currency' => strtoupper((string) $data['currency']),
                'is_unlimited' => (bool) $data['is_unlimited'],
                'data_allowance_bytes' => $data['is_unlimited']
                    ? null
                    : (int) $data['data_allowance_mb'] * 1024 * 1024,
                'validity_seconds' => filled($data['validity_days'] ?? null)
                    ? (int) $data['validity_days'] * 86400
                    : null,
                'time_allowance_seconds' => (int) $data['time_allowance_seconds'],
                'upload_speed_kbps' => $profile['upload_speed_kbps'],
                'download_speed_kbps' => $profile['download_speed_kbps'],
                'is_active' => (bool) $data['is_active'],
                'created_by' => $actor->getKey(),
            ]);

            $plan->profileMappings()->create([
                'router_id' => $router->getKey(),
                'hotspot_profile' => $profile['name'],
            ]);

            return $plan;
        });

        $after = $plan->toArray();
        $after['hotspot_profile'] = $profile['name'];

        $this->audit->record('plan.created', $plan, after: $after, actor: $actor);

        return $plan->load(['router', 'profileMappings']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Plan $plan, array $data): Plan
    {
        $router = Router::query()->visibleTo($actor)->findOrFail($plan->router_id);
        $profile = $this->validatedProfile($router, (string) $data['hotspot_profile']);

        $before = $plan->toArray();
        $before['hotspot_profile'] = $plan->profileMappings()
            ->where('router_id', $plan->router_id)
            ->value('hotspot_profile');

        DB::transaction(function () use ($data, $plan, $profile, $router): void {
            $plan->update([
                'name' => $data['name'],
                'description' => filled($data['description'] ?? null) ? $data['description'] : null,
                'price' => $data['price'],
                'currency' => strtoupper((string) $data['currency']),
                'is_unlimited' => (bool) $data['is_unlimited'],
                'data_allowance_bytes' => $data['is_unlimited']
                    ? null
                    : (int) $data['data_allowance_mb'] * 1024 * 1024,
                'validity_seconds' => filled($data['validity_days'] ?? null)
                    ? (int) $data['validity_days'] * 86400
                    : null,
                'time_allowance_seconds' => (int) $data['time_allowance_seconds'],
                'upload_speed_kbps' => $profile['upload_speed_kbps'],
                'download_speed_kbps' => $profile['download_speed_kbps'],
                'is_active' => (bool) $data['is_active'],
            ]);

            $plan->profileMappings()->updateOrCreate(
                ['router_id' => $router->getKey()],
                ['hotspot_profile' => $profile['name']],
            );

            $plan->vouchers()
                ->whereIn('provisioning_status', [
                    ProvisioningStatus::Pending->value,
                    ProvisioningStatus::Failed->value,
                ])
                ->whereNull('provisioned_at')
                ->update([
                    'hotspot_profile' => $profile['name'],
                    'upload_speed_kbps' => $profile['upload_speed_kbps'],
                    'download_speed_kbps' => $profile['download_speed_kbps'],
                ]);
        });

        $after = $plan->fresh()->toArray();
        $after['hotspot_profile'] = $profile['name'];

        $this->audit->record('plan.updated', $plan, before: $before, after: $after, actor: $actor);

        return $plan->fresh()->load(['router', 'profileMappings']);
    }

    public function destroy(User $actor, Plan $plan): void
    {
        Router::query()->visibleTo($actor)->findOrFail($plan->router_id);

        if ($plan->vouchers()->exists()) {
            throw ValidationException::withMessages([
                'plan' => 'This plan has vouchers. Deactivate it instead of deleting it.',
            ]);
        }

        $before = $plan->toArray();
        $before['hotspot_profile'] = $plan->profileMappings()
            ->where('router_id', $plan->router_id)
            ->value('hotspot_profile');

        $plan->delete();

        $this->audit->record('plan.deleted', $plan, before: $before, actor: $actor);
    }

    private function resolveRouter(User $actor, int|string $identifier): Router
    {
        $query = Router::query()->visibleTo($actor);

        if (is_numeric($identifier)) {
            $query->whereKey((int) $identifier);
        } else {
            $query->where('public_id', (string) $identifier);
        }

        return $query->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array{name: string, rate_limit: ?string, shared_users: string, upload_speed_kbps: ?int, download_speed_kbps: ?int}
     */
    private function validatedProfile(Router $router, string $name): array
    {
        $profile = collect($this->hotspotProfiles($router))->firstWhere('name', $name);

        if ($profile === null) {
            throw ValidationException::withMessages([
                'hotspot_profile' => 'Select a HotSpot profile available on this router.',
            ]);
        }

        if (($profile['upload_speed_kbps'] ?? null) === null && ($profile['download_speed_kbps'] ?? null) === null) {
            return $profile;
        }

        if (($profile['upload_speed_kbps'] ?? null) === null || ($profile['download_speed_kbps'] ?? null) === null) {
            throw ValidationException::withMessages([
                'hotspot_profile' => 'The RouterOS rate limit must specify both upload and download speeds.',
            ]);
        }

        return $profile;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readProfiles(Router $router): array
    {
        try {
            return $this->gateway->hotspotUserProfiles($router, $this->correlation->id());
        } catch (RouterProbeException $exception) {
            throw ValidationException::withMessages([
                'hotspot_profile' => 'Unable to read HotSpot profiles from the router: '.$exception->getMessage(),
            ]);
        }
    }

    private function generateCode(): string
    {
        do {
            $code = 'PLN-'.strtoupper(Str::random(8));
        } while (Plan::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
