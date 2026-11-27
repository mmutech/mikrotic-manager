<?php

namespace App\Http\Resources;

use App\Models\Router;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Router */
class RouterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'identity' => $this->identity,
            'host' => $this->host,
            'rest_port' => $this->rest_port,
            'location' => $this->location,
            'site' => $this->site,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'is_online' => $this->isOnline(),
            'monitoring_enabled' => (bool) $this->monitoring_enabled,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'latency_ms' => $this->latency_ms,
            'consecutive_failures' => $this->consecutive_failures,
            'last_error_category' => $this->last_error_category?->value,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
