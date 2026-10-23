<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('customers.view');
        }
        if ($user->hasRole('support') || $user->hasRole('finance') || $user->hasRole('network-admin')) {
            return $user->can('customers.view') && $user->routers()->exists();
        }
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('customers.view');
        }
        // Customers linked via purchases/transactions to routers
        $routerIds = $user->routers()->pluck('routers.id');
        if ($routerIds->isEmpty()) {
            return false;
        }
        $hasRouterLink = $customer->purchases()->whereIn('router_id', $routerIds)->exists()
            || $customer->transactions()->whereIn('router_id', $routerIds)->exists()
            || $customer->vouchers()->whereIn('router_id', $routerIds)->exists();
        return $user->can('customers.view') && $hasRouterLink;
    }

    public function create(User $user): bool
    {
        return $user->can('customers.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage');
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage');
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return false;
    }
}
