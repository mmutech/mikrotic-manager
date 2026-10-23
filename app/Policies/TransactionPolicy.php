<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('transactions.view');
        }
        if ($user->hasRole('finance') || $user->hasRole('network-admin') || $user->hasRole('support')) {
            return $user->can('transactions.view') && $user->routers()->exists();
        }
        return $user->can('transactions.view');
    }

    public function view(User $user, Transaction $transaction): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('transactions.view');
        }
        if ($user->hasRole('finance') || $user->hasRole('network-admin') || $user->hasRole('support')) {
            return $user->can('transactions.view') && $user->canAccessRouter($transaction->router);
        }
        return $user->can('transactions.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function verify(User $user, Transaction $transaction): bool
    {
        return $user->can('transactions.verify');
    }

    public function refund(User $user, Transaction $transaction): bool
    {
        return $user->can('transactions.refund');
    }
}
