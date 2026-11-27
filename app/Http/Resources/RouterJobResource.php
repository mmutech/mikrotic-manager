<?php

namespace App\Http\Resources;

use App\Models\RouterJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RouterJob */
class RouterJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'router_id' => $this->router_id,
            'type' => $this->type,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'error_category' => $this->error_category,
            'error_message' => $this->error_message,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
