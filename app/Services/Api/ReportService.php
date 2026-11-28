<?php

namespace App\Services\Api;

use App\Enums\RouterJobStatus;
use App\Enums\RouterStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterHealthCheck;
use App\Models\RouterJob;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ReportService
{
    /** @return array<string, mixed> */
    public function overview(User $user): array
    {
        $plans = $this->plansQuery($user);
        $vouchers = $this->vouchersQuery($user);
        $customers = $this->customersQuery($user);
        $purchases = $this->purchasesQuery($user);
        $transactions = $this->transactionsQuery($user);

        return [
            'plans' => [
                'total' => (clone $plans)->count(),
                'active' => (clone $plans)->where('is_active', true)->count(),
            ],
            'vouchers' => [
                'total' => (clone $vouchers)->count(),
                'active' => (clone $vouchers)->where('lifecycle_status', 'active')->count(),
                'provisioned' => (clone $vouchers)->where('provisioning_status', 'provisioned')->count(),
            ],
            'customers' => [
                'total' => (clone $customers)->count(),
            ],
            'purchases' => [
                'total' => (clone $purchases)->count(),
                'fulfilled' => (clone $purchases)->where('status', 'fulfilled')->count(),
            ],
            'transactions' => [
                'total' => (clone $transactions)->count(),
                'paid' => (clone $transactions)->where('status', TransactionStatus::Paid->value)->count(),
            ],
            'revenue' => $this->revenue($user),
            'routers' => $this->routerCounts($user),
            'subscriptions' => $this->subscriptionCounts($user),
        ];
    }

    /** @return array<string, mixed> */
    public function voucherStats(User $user, string $period = 'all'): array
    {
        $query = $this->scopeVoucherPeriod($this->vouchersQuery($user), $period);

        $grouped = (clone $query)
            ->toBase()
            ->selectRaw('lifecycle_status as value, COUNT(*) as total')
            ->groupBy('lifecycle_status')
            ->pluck('total', 'value')
            ->mapWithKeys(fn ($total, string $status): array => [(string) $status => (int) $total])
            ->all();

        $usage = (clone $query)
            ->selectRaw('COALESCE(SUM(used_bytes), 0) as used_bytes, COALESCE(SUM(time_used_seconds), 0) as time_used_seconds')
            ->first();

        $byPlan = (clone $query)
            ->select('plan_id', DB::raw('COUNT(*) as total'))
            ->groupBy('plan_id')
            ->with('plan:id,name')
            ->get()
            ->map(fn ($row): array => [
                'plan_id' => $row->plan_id,
                'plan_name' => $row->plan?->name,
                'total' => (int) $row->total,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'period' => $period,
            'total' => (clone $query)->count(),
            'by_lifecycle' => $grouped,
            'active' => (int) ($grouped['active'] ?? 0),
            'pending' => (int) ($grouped['pending'] ?? 0),
            'revoked' => (int) ($grouped['revoked'] ?? 0),
            'provisioned' => (clone $query)->where('provisioning_status', 'provisioned')->count(),
            'provisioning_failed' => (clone $query)->whereIn('provisioning_status', ['failed', 'revocation_failed'])->count(),
            'expiring_in_7_days' => (clone $query)
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(7)])
                ->count(),
            'data_used_bytes' => (int) ($usage->used_bytes ?? 0),
            'connected_seconds' => (int) ($usage->time_used_seconds ?? 0),
            'by_plan' => $byPlan,
            'recent' => (clone $query)
                ->with(['router:id,name', 'plan:id,name'])
                ->latest()
                ->limit(5)
                ->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function voucherRevenue(User $user, string $period = 'all'): array
    {
        $query = $this->scopeVoucherPeriod(
            $this->vouchersQuery($user)
                ->join('plans', 'plans.id', '=', 'vouchers.plan_id')
                ->where('vouchers.provisioning_status', 'provisioned'),
            $period,
        );

        $byCurrency = (clone $query)
            ->toBase()
            ->select(
                'plans.currency',
                DB::raw('COUNT(*) as total'),
                DB::raw('COALESCE(SUM(plans.price), 0) as amount'),
            )
            ->groupBy('plans.currency')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) $row->currency,
                'vouchers' => (int) $row->total,
                'amount' => (float) $row->amount,
            ])
            ->all();

        return [
            'period' => $period,
            'total_amount' => (float) (clone $query)->sum('plans.price'),
            'voucher_count' => (int) (clone $query)->count(),
            'by_currency' => $byCurrency,
        ];
    }

    /** @return array<string, mixed> */
    public function revenue(User $user): array
    {
        $query = $this->transactionsQuery($user)
            ->where('status', TransactionStatus::Paid->value);

        $byCurrency = (clone $query)
            ->select('currency', DB::raw('COUNT(*) as total'), DB::raw('COALESCE(SUM(amount), 0) as amount'))
            ->groupBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'currency' => $row->currency,
                'transactions' => (int) $row->total,
                'amount' => (float) $row->amount,
            ])
            ->all();

        $monthly = (clone $query)
            ->get(['amount', 'currency', 'paid_at', 'created_at'])
            ->groupBy(fn (Transaction $transaction): string => ($transaction->paid_at ?? $transaction->created_at)?->format('Y-m') ?? 'unknown')
            ->map(fn ($transactions, string $period): array => [
                'period' => $period,
                'transactions' => $transactions->count(),
                'amount' => (float) $transactions->sum('amount'),
                'currencies' => $transactions->pluck('currency')->unique()->values()->all(),
            ])
            ->sortByDesc('period')
            ->values()
            ->all();

        $byRouter = (clone $query)
            ->select('router_id', DB::raw('COALESCE(SUM(amount), 0) as amount'))
            ->groupBy('router_id')
            ->with('router:id,name')
            ->get()
            ->map(fn ($row): array => [
                'router_id' => $row->router_id,
                'router_name' => $row->router?->name,
                'amount' => (float) $row->amount,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $byPlan = (clone $query)
            ->select('plan_id', DB::raw('COALESCE(SUM(amount), 0) as amount'))
            ->groupBy('plan_id')
            ->with('plan:id,name')
            ->get()
            ->map(fn ($row): array => [
                'plan_id' => $row->plan_id,
                'plan_name' => $row->plan?->name,
                'amount' => (float) $row->amount,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        return [
            'total_amount' => (float) (clone $query)->sum('amount'),
            'transaction_count' => (clone $query)->count(),
            'by_currency' => $byCurrency,
            'monthly' => $monthly,
            'by_router' => $byRouter,
            'by_plan' => $byPlan,
        ];
    }

    /** @return array<string, mixed> */
    public function routers(User $user): array
    {
        $visibleRouterIds = Router::query()->visibleTo($user)->pluck('routers.id');
        $query = Router::query()->visibleTo($user);
        $statuses = $this->groupCounts((clone $query), 'status');

        $pendingJobs = $visibleRouterIds->isEmpty()
            ? 0
            : RouterJob::query()
                ->whereIn('router_id', $visibleRouterIds)
                ->whereIn('status', [
                    RouterJobStatus::Queued->value,
                    RouterJobStatus::Running->value,
                    RouterJobStatus::Retrying->value,
                ])
                ->count();

        $recentChecks = $visibleRouterIds->isEmpty()
            ? collect()
            : RouterHealthCheck::query()
                ->with('router:id,name')
                ->whereIn('router_id', $visibleRouterIds)
                ->latest('checked_at')
                ->limit(6)
                ->get();

        return [
            'total' => (clone $query)->count(),
            'by_status' => $statuses,
            'online' => (int) ($statuses[RouterStatus::Online->value] ?? 0),
            'needs_attention' => (int) ($statuses[RouterStatus::Offline->value] ?? 0)
                + (int) ($statuses[RouterStatus::Error->value] ?? 0),
            'monitoring' => (clone $query)->monitoring()->count(),
            'pending_jobs' => $pendingJobs,
            'routers' => (clone $query)->orderBy('name')->get(),
            'recent_checks' => $recentChecks,
        ];
    }

    /** @return array<string, mixed> */
    public function subscriptions(User $user): array
    {
        $query = Subscription::query();

        if (! $user->isSuperAdmin()) {
            $query->where('user_id', $user->getKey());
        }

        $statuses = $this->groupCounts((clone $query), 'status');
        $types = $this->groupCounts((clone $query), 'type');
        $active = (clone $query)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->count();

        $expiringSoon = (clone $query)
            ->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->count();

        $missing = $user->isSuperAdmin()
            ? User::query()
                // Roles are created with the `web` guard; API auth switches the default
                // guard to `sanctum`, so resolve the roles explicitly under `web`.
                ->role(Roles::SUBSCRIBERS, 'web')
                ->whereDoesntHave('subscriptions', fn ($query) => $query
                    ->where('status', 'active')
                    ->where('expires_at', '>', now()))
                ->count()
            : 0;

        return [
            'total' => (clone $query)->count(),
            'by_status' => $statuses,
            'by_type' => $types,
            'active' => $active,
            'expiring_in_7_days' => $expiringSoon,
            'network_admins_without_active_subscription' => $missing,
            'subscribers_without_active_subscription' => $missing,
            'requires_subscription' => $user->requiresSubscription(),
            'mine' => [
                'has_active' => $user->hasActiveSubscription(),
                'active_until' => $user->activeSubscription()?->expires_at?->toIso8601String(),
            ],
            'recent' => (clone $query)->with('user:id,name,email')->latest()->limit(6)->get(),
        ];
    }

    private function routerCounts(User $user): array
    {
        $report = $this->routers($user);

        return [
            'total' => $report['total'],
            'online' => $report['online'],
            'needs_attention' => $report['needs_attention'],
            'monitoring' => $report['monitoring'],
            'pending_jobs' => $report['pending_jobs'],
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionCounts(User $user): array
    {
        $report = $this->subscriptions($user);

        return [
            'total' => $report['total'],
            'by_type' => $report['by_type'],
            'active' => $report['active'],
            'expiring_in_7_days' => $report['expiring_in_7_days'],
            'requires_subscription' => $report['requires_subscription'],
            'mine' => $report['mine'],
        ];
    }

    private function plansQuery(User $user): Builder
    {
        $query = Plan::query();

        if (! $user->isSuperAdmin()) {
            $query->whereIn('router_id', $this->visibleRouterIds($user));
        }

        return $query;
    }

    private function vouchersQuery(User $user): Builder
    {
        return $this->scopeByRouter(Voucher::query(), $user);
    }

    private function purchasesQuery(User $user): Builder
    {
        return $this->scopeByRouter(Purchase::query(), $user);
    }

    private function transactionsQuery(User $user): Builder
    {
        return $this->scopeByRouter(Transaction::query(), $user);
    }

    private function customersQuery(User $user): Builder
    {
        $query = Customer::query();

        if (! $user->isSuperAdmin()) {
            $routerIds = $this->visibleRouterIds($user);

            $query->where(function (Builder $query) use ($routerIds): void {
                $query->whereHas('purchases', fn ($query) => $query->whereIn('router_id', $routerIds))
                    ->orWhereHas('transactions', fn ($query) => $query->whereIn('router_id', $routerIds))
                    ->orWhereHas('vouchers', fn ($query) => $query->whereIn('router_id', $routerIds));
            });
        }

        return $query;
    }

    private function scopeVoucherPeriod(Builder $query, string $period): Builder
    {
        if ($period === 'all') {
            return $query;
        }

        [$start, $end] = match ($period) {
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };

        return $query->whereBetween('vouchers.created_at', [$start, $end]);
    }

    private function scopeByRouter(Builder $query, User $user): Builder
    {
        if (! $user->isSuperAdmin()) {
            $query->whereIn($query->getModel()->qualifyColumn('router_id'), $this->visibleRouterIds($user));
        }

        return $query;
    }

    private function visibleRouterIds(User $user)
    {
        return Router::query()->visibleTo($user)->select('routers.id');
    }

    /** @return array<string, int> */
    private function groupCounts(Builder $query, string $column): array
    {
        return $query
            ->toBase()
            ->selectRaw("{$column} as value, COUNT(*) as total")
            ->groupBy($column)
            ->pluck('total', 'value')
            ->mapWithKeys(fn ($total, $value): array => [(string) $value => (int) $total])
            ->all();
    }
}
