<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $mapping = $this->profileMappings->firstWhere('router_id', $this->router_id);

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'router_id' => $this->router_id,
            'router_name' => $this->whenLoaded('router', fn () => $this->router?->name),
            'hotspot_profile' => $mapping?->hotspot_profile,
            'data_allowance_bytes' => $this->data_allowance_bytes,
            'quota_label' => $this->quotaLabel(),
            'is_unlimited' => (bool) $this->is_unlimited,
            'validity_seconds' => $this->validity_seconds,
            'validity_label' => $this->validityLabel(),
            'time_allowance_seconds' => $this->time_allowance_seconds,
            'connected_time_label' => (int) $this->time_allowance_seconds === 0
                ? 'No maximum time limit'
                : $this->connectedTimeLabel(),
            'upload_speed_kbps' => $this->upload_speed_kbps,
            'download_speed_kbps' => $this->download_speed_kbps,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
