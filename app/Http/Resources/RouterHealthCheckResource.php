<?php

namespace App\Http\Resources;

use App\Models\RouterHealthCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RouterHealthCheck */
class RouterHealthCheckResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'latency_ms' => $this->latency_ms,
            'identity' => $this->identity,
            'ros_version' => $this->ros_version,
            'error_category' => $this->error_category,
            'details' => $this->details,
            'checked_at' => $this->checked_at?->toIso8601String(),
        ];
    }
}
