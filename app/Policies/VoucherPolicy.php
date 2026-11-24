<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voucher;

class VoucherPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->can('vouchers.view')) {
            return false;
        }

        return $user->isSuperAdmin() || $user->routers()->exists();
    }

    public function view(User $user, Voucher $voucher): bool
    {
        return $user->can('vouchers.view') && $this->canAccessVoucherRouter($user, $voucher);
    }

    public function create(User $user): bool
    {
        return $user->can('vouchers.generate');
    }

    public function update(User $user, Voucher $voucher): bool
    {
        return $user->can('vouchers.generate') && $this->canAccessVoucherRouter($user, $voucher);
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
        return $user->can('vouchers.provision') && $this->canAccessVoucherRouter($user, $voucher);
    }

    public function revoke(User $user, Voucher $voucher): bool
    {
        return $user->can('vouchers.revoke') && $this->canAccessVoucherRouter($user, $voucher);
    }

    public function print(User $user, Voucher $voucher): bool
    {
        return $user->can('vouchers.print') && $this->canAccessVoucherRouter($user, $voucher);
    }

    public function restore(User $user, Voucher $voucher): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Voucher $voucher): bool
    {
        return false;
    }

    private function canAccessVoucherRouter(User $user, Voucher $voucher): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $voucher->router !== null && $user->canAccessRouter($voucher->router);
    }
}
