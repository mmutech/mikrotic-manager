<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'router_id' => $this->router_id,
            'plan' => $this->type->value,
            'type' => $this->type->value,
            'type_label' => $this->typeLabel(),
            'duration_days' => $this->durationDays(),
            'price' => $this->amount,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'days_remaining' => $this->daysRemaining(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'is_active' => $this->isActive(),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
