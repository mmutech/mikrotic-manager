<?php

namespace App\Http\Resources;

use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Voucher */
class VoucherResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'username' => $this->username,
            'password' => $this->when($this->show_credentials === true, fn () => $this->plainPassword()),
            'router_id' => $this->router_id,
            'router_name' => $this->whenLoaded('router', fn () => $this->router?->name),
            'plan_id' => $this->plan_id,
            'plan_name' => $this->whenLoaded('plan', fn () => $this->plan?->name),
            'hotspot_profile' => $this->hotspot_profile,
            'lifecycle_status' => $this->lifecycle_status?->value,
            'lifecycle_label' => $this->lifecycle_status?->label(),
            'provisioning_status' => $this->provisioning_status?->value,
            'provisioning_label' => $this->provisioning_status?->label(),
            'data_allowance_bytes' => $this->data_allowance_bytes,
            'used_bytes' => $this->used_bytes,
            'remaining_bytes' => $this->remainingBytes(),
            'quota_label' => $this->quotaLabel(),
            'time_allowance_seconds' => $this->time_allowance_seconds,
            'time_used_seconds' => $this->time_used_seconds,
            'upload_speed_kbps' => $this->upload_speed_kbps,
            'download_speed_kbps' => $this->download_speed_kbps,
            'validity_seconds' => $this->validity_seconds,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'first_used_at' => $this->first_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'provisioned_at' => $this->provisioned_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'provisioning_error' => $this->provisioning_error,
            'notes' => $this->notes,
            'is_usable' => $this->isUsable(),
            'can_be_provisioned' => $this->canBeProvisioned(),
            'actions' => [
                'provision' => $user !== null && $user->can('provision', $this->resource),
                'revoke' => $user !== null && $user->can('revoke', $this->resource),
                'print' => $user !== null && $user->can('print', $this->resource),
            ],
            'connected_time' => $this->live_usage['duration'] ?? null,
            'consumption' => $this->live_usage['consumption'] ?? null,
            'live_usage' => $this->when(
                $this->live_usage !== null,
                fn () => [
                    'status' => $this->live_usage['status'],
                    'connected_time' => $this->live_usage['duration'],
                    'consumption' => $this->live_usage['consumption'],
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
