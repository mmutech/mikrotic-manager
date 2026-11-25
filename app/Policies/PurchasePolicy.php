<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->can('transactions.view')) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->hasRole('finance')) {
            return true;
        }

        return $user->hasRole(['support', 'network-admin']) && $user->routers()->exists();
    }

    public function view(User $user, Purchase $purchase): bool
    {
        if (! $user->can('transactions.view')) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->hasRole('finance')) {
            return true;
        }

        return $user->hasRole(['support', 'network-admin'])
            && $user->canAccessRouter($purchase->router);
    }
}
