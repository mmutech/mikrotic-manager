<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voucher;

class VoucherPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('vouchers.view');
        }
        if ($user->hasRole('network-admin') || $user->hasRole('support')) {
            return $user->can('vouchers.view') && $user->routers()->exists();
        }
        return $user->can('vouchers.view');
    }

    public function view(User $user, Voucher $voucher): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('vouchers.view');
        }
        if ($user->hasRole('network-admin') || $user->hasRole('support')) {
            return $user->can('vouchers.view') && $user->canAccessRouter($voucher->router);
        }
        return $user->can('vouchers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('vouchers.generate');
    }

    public function update(User $user, Voucher $voucher): bool
    {
        return $user->can('vouchers.generate');
    }

    public function delete(User $user, Voucher $voucher): bool
    {
        return $user->isSuperAdmin();
    }

    public function generate(User $user): bool
    {
        return $user->can('vouchers.generate');
    }

    public function provision(User $user, Voucher $voucher): bool
    {
        return $user->can('vouchers.provision');
    }

    public function revoke(User $user, Voucher $voucher): bool
    {
        return $user->can('vouchers.revoke');
    }

    public function print(User $user, Voucher $voucher): bool
    {
        return $user->can('vouchers.print');
    }

    public function restore(User $user, Voucher $voucher): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Voucher $voucher): bool
    {
        return false;
    }
}
