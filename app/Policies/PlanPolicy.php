<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('plans.view') || $user->can('plans.manage');
        }
        if ($user->hasRole('network-admin') || $user->hasRole('support') || $user->hasRole('finance')) {
            return ($user->can('plans.view') || $user->can('plans.manage')) && $user->routers()->exists();
        }
        return $user->can('plans.view') || $user->can('plans.manage');
    }

    public function view(User $user, Plan $plan): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('plans.view') || $user->can('plans.manage');
        }
        if ($plan->router_id && $user->routers()->exists()) {
            return ($user->can('plans.view') || $user->can('plans.manage')) && $user->canAccessRouter($plan->router);
        }
        return ($user->can('plans.view') || $user->can('plans.manage')) && $user->routers()->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('plans.manage');
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage');
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage');
    }

    public function restore(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage');
    }

    public function forceDelete(User $user, Plan $plan): bool
    {
        return false;
    }
}
