<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Support\Roles;

class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('plans.view') || $user->can('plans.manage');
        }

        // Sales agents may read plans so they can generate vouchers, but only
        // for routers assigned to them. The controller applies the same scope.
        if ($user->hasRole([...Roles::SUBSCRIBERS, 'support', 'finance'])) {
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
        return $user->can('plans.manage') && $this->canAccessPlanRouter($user, $plan);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage') && $this->canAccessPlanRouter($user, $plan);
    }

    public function restore(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage');
    }

    public function forceDelete(User $user, Plan $plan): bool
    {
        return false;
    }

    private function canAccessPlanRouter(User $user, Plan $plan): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($plan->router_id === null) {
            return false;
        }

        return $plan->router !== null && $user->canAccessRouter($plan->router);
    }
}
