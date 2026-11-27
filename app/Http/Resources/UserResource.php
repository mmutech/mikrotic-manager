<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $active = $this->activeSubscription();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'roles' => $this->roles->pluck('name')->all(),
            'permissions' => $this->getAllPermissions()->pluck('name')->all(),
            'requires_subscription' => $this->requiresSubscription(),
            'has_active_subscription' => $this->hasActiveSubscription(),
            'active_subscription' => $active !== null ? new SubscriptionResource($active) : null,
            'must_change_password' => (bool) $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
