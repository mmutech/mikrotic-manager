<?php

namespace App\Policies;

use App\Models\Router;
use App\Models\User;

class RouterPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('routers.view');
        }
        if ($user->hasRole('network-admin') || $user->hasRole('support')) {
            return $user->can('routers.view');
        }
        return $user->can('routers.view');
    }

    public function view(User $user, Router $router): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('routers.view');
        }
        return $user->can('routers.view') && $user->canAccessRouter($router);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() && $user->can('routers.manage');
    }

    public function update(User $user, Router $router): bool
    {
        if ($user->isSuperAdmin()) {
            return $user->can('routers.manage') && $user->canAccessRouter($router);
        }
        if ($user->hasRole('network-admin')) {
            return $user->can('routers.manage') && $user->canAccessRouter($router);
        }
        return false;
    }

    /**
     * Removing a router is reserved for super administrators. Network
     * administrators can disable monitoring or archive their own routers
     * through the update policy if granted that permission explicitly.
     */
    public function delete(User $user, Router $router): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, Router $router): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Router $router): bool
    {
        return false;
    }

    public function test(User $user, Router $router): bool
    {
        return $user->can('routers.test') && $user->canAccessRouter($router);
    }

    public function manageMonitoring(User $user, Router $router): bool
    {
        return $user->can('routers.manage') && $user->canAccessRouter($router);
    }

    /**
     * Router access is an administrative boundary. Only super administrators
     * may grant or revoke a user's access to a router.
     */
    public function grantAccess(User $user, Router $router): bool
    {
        return $user->isSuperAdmin();
    }

    public function revokeAccess(User $user, Router $router): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Even a super administrator cannot read an existing router password
     * back out of the application. They can only set a new one.
     */
    public function viewCredentials(User $user, Router $router): bool
    {
        return false;
    }
}
