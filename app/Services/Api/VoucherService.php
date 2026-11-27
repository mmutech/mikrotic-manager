<?php

namespace App\Services\Api;

use App\Enums\ProvisioningStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Jobs\ProvisionVoucherJob;
use App\Jobs\RevokeVoucherJob;
use App\Models\Plan;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VoucherService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{router_id: int|string, plan_id: int|string, quantity: int|string, prefix?: string|null}  $data
     * @return Collection<int, Voucher>
     */
    public function generate(User $actor, array $data): Collection
    {
        $router = $this->resolveRouter($actor, $data['router_id']);

        $plan = Plan::query()
            ->where('router_id', $router->getKey())
            ->where('is_active', true)
            ->tap(fn ($query) => $this->resolveIdentifier($query, $data['plan_id']))
            ->firstOrFail();

        $mapping = $plan->profileMappings()
            ->where('router_id', $router->getKey())
            ->first();

        if ($mapping === null || blank($mapping->hotspot_profile)) {
            throw ValidationException::withMessages([
                'plan_id' => 'Map this plan to an existing RouterOS HotSpot user profile before generating vouchers.',
            ]);
        }

        $this->assertPlanIsUsable($plan);

        $quantity = (int) $data['quantity'];
        $prefix = filled($data['prefix'] ?? null)
            ? strtoupper((string) $data['prefix']).'-'
            : '';

        $vouchers = DB::transaction(function () use ($actor, $mapping, $plan, $prefix, $quantity, $router): Collection {
            $created = new Collection;

            for ($index = 0; $index < $quantity; $index++) {
                do {
                    $username = $prefix.strtoupper(Str::random(6));
                } while (Voucher::query()->where('username', $username)->exists());

                $created->push(Voucher::create([
                    'router_id' => $router->getKey(),
                    'plan_id' => $plan->getKey(),
                    'hotspot_profile' => $mapping->hotspot_profile,
                    'generated_by' => $actor->getKey(),
                    'username' => $username,
                    'password' => $username,
                    'data_allowance_bytes' => $plan->is_unlimited ? null : $plan->data_allowance_bytes,
                    'time_allowance_seconds' => $plan->time_allowance_seconds,
                    'upload_speed_kbps' => $plan->upload_speed_kbps,
                    'download_speed_kbps' => $plan->download_speed_kbps,
                    'validity_seconds' => $plan->validity_seconds,
                    'validity_starts' => $plan->validity_starts,
                    'expiry_policy' => $plan->expiry_policy,
                    'lifecycle_status' => VoucherLifecycleStatus::Pending,
                    'provisioning_status' => ProvisioningStatus::Pending,
                ]));
            }

            return $created;
        });

        $this->audit->record('vouchers.generated', $router, metadata: [
            'count' => $vouchers->count(),
            'plan_id' => $plan->getKey(),
            'source' => 'mobile-api',
        ], actor: $actor);

        return $vouchers;
    }

    public function provision(User $actor, Voucher $voucher): Voucher
    {
        $this->assertProvisionable($voucher);

        $claimed = Voucher::query()
            ->whereKey($voucher->getKey())
            ->whereIn('provisioning_status', [
                ProvisioningStatus::Pending->value,
                ProvisioningStatus::Failed->value,
            ])
            ->update([
                'provisioning_status' => ProvisioningStatus::Queued,
                'provisioning_error' => null,
            ]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'voucher' => 'This voucher is no longer eligible for provisioning. Refresh and try again.',
            ]);
        }

        $voucher->refresh();

        ProvisionVoucherJob::dispatch($voucher->getKey(), $actor->getKey())->afterCommit();

        $this->audit->record('voucher.provision_queued', $voucher, metadata: [
            'source' => 'mobile-api',
        ], actor: $actor);

        return $voucher;
    }

    public function revoke(User $actor, Voucher $voucher): Voucher
    {
        $this->assertRevocable($voucher);

        $claimed = Voucher::query()
            ->whereKey($voucher->getKey())
            ->where('lifecycle_status', '!=', VoucherLifecycleStatus::Revoked->value)
            ->whereNotIn('provisioning_status', [
                ProvisioningStatus::Disabled->value,
                ProvisioningStatus::Removed->value,
                ProvisioningStatus::Disabling->value,
            ])
            ->update([
                'provisioning_status' => ProvisioningStatus::Disabling,
                'provisioning_error' => null,
            ]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'voucher' => 'This voucher is no longer eligible for revocation. Refresh and try again.',
            ]);
        }

        $voucher->refresh();

        RevokeVoucherJob::dispatch($voucher->getKey(), $actor->getKey())->afterCommit();

        $this->audit->record('voucher.revocation_queued', $voucher, metadata: [
            'source' => 'mobile-api',
        ], actor: $actor);

        return $voucher;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{requested: int, queued: int, skipped: int}
     */
    public function provisionBulk(User $actor, array $ids): array
    {
        $vouchers = $this->visibleVouchers($actor, $ids);

        foreach ($vouchers as $voucher) {
            $this->assertProvisionable($voucher);
        }

        $queued = 0;

        foreach ($vouchers as $voucher) {
            $updated = Voucher::query()
                ->whereKey($voucher->getKey())
                ->whereIn('provisioning_status', [
                    ProvisioningStatus::Pending->value,
                    ProvisioningStatus::Failed->value,
                ])
                ->update([
                    'provisioning_status' => ProvisioningStatus::Queued,
                    'provisioning_error' => null,
                ]);

            if ($updated !== 1) {
                continue;
            }

            ProvisionVoucherJob::dispatch($voucher->getKey(), $actor->getKey())->afterCommit();
            $queued++;
        }

        $this->audit->record('vouchers.provision_queued', null, metadata: [
            'count' => $queued,
            'voucher_ids' => $vouchers->modelKeys(),
            'source' => 'mobile-api',
        ], actor: $actor);

        return [
            'requested' => count($ids),
            'queued' => $queued,
            'skipped' => count($ids) - $queued,
        ];
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{requested: int, queued: int, skipped: int}
     */
    public function revokeBulk(User $actor, array $ids): array
    {
        $vouchers = $this->visibleVouchers($actor, $ids);

        foreach ($vouchers as $voucher) {
            $this->assertRevocable($voucher);
        }

        $queued = 0;

        foreach ($vouchers as $voucher) {
            $updated = Voucher::query()
                ->whereKey($voucher->getKey())
                ->where('lifecycle_status', '!=', VoucherLifecycleStatus::Revoked->value)
                ->whereNotIn('provisioning_status', [
                    ProvisioningStatus::Disabled->value,
                    ProvisioningStatus::Removed->value,
                    ProvisioningStatus::Disabling->value,
                ])
                ->update([
                    'provisioning_status' => ProvisioningStatus::Disabling,
                    'provisioning_error' => null,
                ]);

            if ($updated !== 1) {
                continue;
            }

            RevokeVoucherJob::dispatch($voucher->getKey(), $actor->getKey())->afterCommit();
            $queued++;
        }

        $this->audit->record('vouchers.revocation_queued', null, metadata: [
            'count' => $queued,
            'voucher_ids' => $vouchers->modelKeys(),
            'source' => 'mobile-api',
        ], actor: $actor);

        return [
            'requested' => count($ids),
            'queued' => $queued,
            'skipped' => count($ids) - $queued,
        ];
    }

    /**
     * @param  array{notes?: string|null}  $data
     */
    public function update(User $actor, Voucher $voucher, array $data): Voucher
    {
        $before = $voucher->auditSnapshot();

        $voucher->update([
            'notes' => $data['notes'] ?? null,
        ]);

        $voucher->refresh();

        $this->audit->record('voucher.updated', $voucher, before: $before, after: $voucher->auditSnapshot(), actor: $actor);

        return $voucher;
    }

    private function resolveRouter(User $actor, int|string $identifier): Router
    {
        return Router::query()
            ->visibleTo($actor)
            ->tap(fn ($query) => $this->resolveIdentifier($query, $identifier))
            ->firstOrFail();
    }

    /**
     * Accept either a numeric database id or a public ULID from mobile clients.
     *
     * @param  Builder<Router|Plan>  $query
     */
    private function resolveIdentifier($query, int|string $identifier): void
    {
        if (is_numeric($identifier)) {
            $query->whereKey((int) $identifier);

            return;
        }

        $query->where('public_id', (string) $identifier);
    }

    private function assertPlanIsUsable(Plan $plan): void
    {
        $messages = [];

        if (! $plan->is_unlimited && ($plan->data_allowance_bytes === null || $plan->data_allowance_bytes <= 0)) {
            $messages['plan_id'] = 'A finite plan data allowance must be greater than zero bytes.';
        }

        if ($plan->time_allowance_seconds !== null && $plan->time_allowance_seconds < 0) {
            $messages['plan_id'] = 'The plan connected-time allowance cannot be negative.';
        }

        if (($plan->upload_speed_kbps === null) !== ($plan->download_speed_kbps === null)) {
            $messages['plan_id'] = 'The plan must set both upload and download limits, or neither.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function assertProvisionable(Voucher $voucher): void
    {
        if (! in_array($voucher->provisioning_status, [
            ProvisioningStatus::Pending,
            ProvisioningStatus::Failed,
        ], true) || ! $voucher->canBeProvisioned()) {
            throw ValidationException::withMessages([
                'voucher' => 'Only pending or failed vouchers that have not expired or been exhausted can be provisioned.',
            ]);
        }
    }

    private function assertRevocable(Voucher $voucher): void
    {
        if ($voucher->lifecycle_status === VoucherLifecycleStatus::Revoked
            || in_array($voucher->provisioning_status, [
                ProvisioningStatus::Disabled,
                ProvisioningStatus::Removed,
                ProvisioningStatus::Disabling,
            ], true)) {
            throw ValidationException::withMessages([
                'voucher' => 'This voucher is not eligible for revocation.',
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Voucher>
     */
    private function visibleVouchers(User $actor, array $ids): Collection
    {
        $numericIds = [];
        $publicIds = [];

        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $numericIds[] = (int) $id;
            } else {
                $publicIds[] = (string) $id;
            }
        }

        $query = Voucher::query()->where(function ($query) use ($numericIds, $publicIds): void {
            if ($numericIds !== []) {
                $query->orWhereIn('id', $numericIds);
            }

            if ($publicIds !== []) {
                $query->orWhereIn('public_id', $publicIds);
            }
        });

        if (! $actor->isSuperAdmin()) {
            $query->whereIn('router_id', Router::query()->visibleTo($actor)->select('routers.id'));
        }

        $vouchers = $query->get();

        if ($vouchers->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'voucher_ids' => 'One or more selected vouchers are unavailable.',
            ]);
        }

        return $vouchers;
    }
}
