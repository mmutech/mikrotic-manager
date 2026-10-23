<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\PlanPolicy;
use App\Policies\RouterPolicy;
use App\Policies\TransactionPolicy;
use App\Policies\UserPolicy;
use App\Policies\VoucherPolicy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useTailwind();

        Gate::policy(Router::class, RouterPolicy::class);
        Gate::policy(Plan::class, PlanPolicy::class);
        Gate::policy(Voucher::class, VoucherPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(\App\Models\AuditLog::class, AuditLogPolicy::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        /*
         * Super administrators bypass permission checks for operational
         * abilities, but never for reading secrets back out of the database.
         */
        Gate::before(function (User $user, string $ability): ?bool {
            if ($ability === 'viewCredentials') {
                return null;
            }

            return $user->isSuperAdmin() ? true : null;
        });
    }
}
