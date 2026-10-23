<?php

use App\Enums\RouterJobStatus;
use App\Enums\RouterStatus;
use App\Models\AuditLog;
use App\Models\Router;
use App\Models\RouterHealthCheck;
use App\Models\RouterJob;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Dashboard')] class extends Component
{
    public function render()
    {
        $user = auth()->user();
        $canViewDashboard = $user->can('dashboard.view');

        $routerQuery = Router::query()->visibleTo($user);
        $routerIds = (clone $routerQuery)->pluck('id');

        $stats = [
            'total' => (clone $routerQuery)->count(),
            'online' => (clone $routerQuery)->where('status', RouterStatus::Online->value)->count(),
            'attention' => (clone $routerQuery)->whereIn('status', [
                RouterStatus::Offline->value,
                RouterStatus::Error->value,
            ])->count(),
            'pending' => (clone $routerQuery)->where('status', RouterStatus::Pending->value)->count(),
            'monitoring' => (clone $routerQuery)->monitoring()->count(),
        ];

        $pendingJobs = $routerIds->isEmpty()
            ? 0
            : RouterJob::query()
                ->whereIn('router_id', $routerIds)
                ->whereIn('status', [
                    RouterJobStatus::Queued->value,
                    RouterJobStatus::Running->value,
                    RouterJobStatus::Retrying->value,
                ])
                ->count();

        $recentRouters = (clone $routerQuery)
            ->orderByRaw('last_checked_at IS NULL')
            ->orderByDesc('last_checked_at')
            ->orderBy('name')
            ->limit(6)
            ->get();

        $recentChecks = $routerIds->isEmpty()
            ? collect()
            : RouterHealthCheck::query()
                ->with('router')
                ->whereIn('router_id', $routerIds)
                ->latest('checked_at')
                ->limit(6)
                ->get();

        $recentAudit = $user->can('audit.view')
            ? AuditLog::query()->with('actor')->latest('created_at')->limit(6)->get()
            : collect();

        return $this->view([
            'canViewDashboard' => $canViewDashboard,
            'stats' => $stats,
            'pendingJobs' => $pendingJobs,
            'recentRouters' => $recentRouters,
            'recentChecks' => $recentChecks,
            'recentAudit' => $recentAudit,
            'canCreateRouter' => $user->can('create', Router::class),
            'canViewRouters' => $user->can('viewAny', Router::class),
            'canViewAudit' => $user->can('viewAny', AuditLog::class),
        ])->title('Dashboard');
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Operations overview</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                {{ now()->hour < 12 ? 'Good morning' : (now()->hour < 17 ? 'Good afternoon' : 'Good evening') }}, {{ auth()->user()->name }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ now()->format('l, j F Y') }} · Signed in as {{ implode(', ', auth()->user()->roleLabels()) ?: 'no role' }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($canCreateRouter)
                <a href="{{ route('routers.create') }}" class="mm-btn-primary">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Register router
                </a>
            @endif

            @if ($canViewRouters)
                <a href="{{ route('routers.index') }}" class="mm-btn-secondary">View all routers</a>
            @endif
        </div>
    </div>

    @if (! $canViewDashboard)
        <div class="mm-card p-6 sm:p-8">
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 19.5a7.5 7.5 0 0 1 15 0v.75H4.5v-.75Z" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Your account is active</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                        Your role does not include the staff operations dashboard. Customer self-service is not enabled in this deployment; contact
                        <a href="mailto:{{ config('mmutech.brand.support_email') }}" class="font-semibold text-brand-600 hover:text-brand-700">{{ config('mmutech.brand.support_email') }}</a>
                        for account, bundle, or connectivity help.
                    </p>
                    <a href="{{ route('account.password') }}" class="mm-btn-secondary mt-5">Manage your password</a>
                </div>
            </div>
        </div>
    @else
        <section aria-label="Router statistics" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="mm-card p-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Registered routers</p>
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M2.454 8.893c5.249-5.25 13.843-5.25 19.092 0" />
                            <circle cx="12" cy="19" r="1.25" fill="currentColor" stroke="none" />
                        </svg>
                    </span>
                </div>
                <p class="mt-3 text-3xl font-bold tracking-tight text-slate-900">{{ number_format($stats['total']) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ number_format($stats['monitoring']) }} with monitoring enabled</p>
            </div>

            <div class="mm-card p-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Online now</p>
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-success-50 text-success-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </span>
                </div>
                <p class="mt-3 text-3xl font-bold tracking-tight text-slate-900">{{ number_format($stats['online']) }}</p>
                <p class="mt-1 text-xs text-slate-500">Passed the latest read-only probe</p>
            </div>

            <div class="mm-card p-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Needs attention</p>
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-red-50 text-red-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008ZM10.34 3.94 2.82 17a1.5 1.5 0 0 0 1.3 2.25h15.76a1.5 1.5 0 0 0 1.3-2.25L13.66 3.94a1.5 1.5 0 0 0-2.6 0Z" />
                        </svg>
                    </span>
                </div>
                <p class="mt-3 text-3xl font-bold tracking-tight text-slate-900">{{ number_format($stats['attention']) }}</p>
                <p class="mt-1 text-xs text-slate-500">Offline or configuration errors</p>
            </div>

            <div class="mm-card p-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pending verification</p>
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-warning-50 text-warning-500">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </span>
                </div>
                <p class="mt-3 text-3xl font-bold tracking-tight text-slate-900">{{ number_format($stats['pending']) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ number_format($pendingJobs) }} queued or running probe{{ $pendingJobs === 1 ? '' : 's' }}</p>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="mm-card overflow-hidden xl:col-span-2" aria-labelledby="recent-routers-heading">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div>
                        <h2 id="recent-routers-heading" class="text-base font-semibold text-slate-900">Router estate</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Most recently verified devices you can access</p>
                    </div>
                    @if ($canViewRouters)
                        <a href="{{ route('routers.index') }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View all</a>
                    @endif
                </div>

                @if ($recentRouters->isEmpty())
                    <div class="px-5 py-10 text-center sm:px-6">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M2.454 8.893c5.249-5.25 13.843-5.25 19.092 0" />
                                <circle cx="12" cy="19" r="1.25" fill="currentColor" stroke="none" />
                            </svg>
                        </span>
                        <h3 class="mt-3 text-sm font-semibold text-slate-900">No routers registered yet</h3>
                        <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">Register your first RouterOS 7 device to verify read-only connectivity and start tracking health.</p>
                        @if ($canCreateRouter)
                            <a href="{{ route('routers.create') }}" class="mm-btn-primary mt-5">Register a router</a>
                        @endif
                    </div>
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($recentRouters as $router)
                            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('routers.show', $router) }}" class="truncate text-sm font-semibold text-slate-900 hover:text-brand-700">{{ $router->name }}</a>
                                        <span class="mm-badge {{ $router->status->badgeClasses() }}">{{ $router->status->label() }}</span>
                                        @unless ($router->monitoring_enabled)
                                            <span class="mm-badge bg-slate-100 text-slate-600 ring-slate-500/20">Monitoring off</span>
                                        @endunless
                                    </div>
                                    <p class="mt-1 truncate text-xs text-slate-500">
                                        {{ $router->host }}:{{ $router->rest_port }}
                                        @if ($router->site) · {{ $router->site }} @endif
                                        @if ($router->location) · {{ $router->location }} @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-4 text-xs text-slate-500">
                                    @if ($router->latency_ms !== null)
                                        <span class="font-medium text-slate-700">{{ $router->latency_ms }} ms</span>
                                    @endif
                                    <span>{{ $router->last_checked_at?->diffForHumans() ?? 'Never checked' }}</span>
                                    <a href="{{ route('routers.show', $router) }}" class="font-semibold text-brand-600 hover:text-brand-700">Open</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="space-y-6">
                <div class="mm-card overflow-hidden">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-semibold text-slate-900">Security posture</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Effective controls in this environment</p>
                    </div>
                    <dl class="divide-y divide-slate-100 text-sm">
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <dt class="text-slate-600">Plain HTTP</dt>
                            <dd class="font-semibold {{ config('mikrotik.allow_http') ? 'text-amber-600' : 'text-emerald-700' }}">{{ config('mikrotik.allow_http') ? 'Enabled' : 'Blocked' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <dt class="text-slate-600">TLS verification</dt>
                            <dd class="font-semibold text-emerald-700">{{ app()->environment('production') ? 'Enforced' : 'Per router' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <dt class="text-slate-600">Management ranges</dt>
                            <dd class="font-semibold text-slate-900">{{ count((array) config('mikrotik.management_networks')) }} allowlisted</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <dt class="text-slate-600">Health schedule</dt>
                            <dd class="font-semibold text-slate-900">Every 5 minutes</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <dt class="text-slate-600">API log retention</dt>
                            <dd class="font-semibold text-slate-900">{{ config('mikrotik.api_log_retention_days') }} days</dd>
                        </div>
                    </dl>
                </div>

                <div class="mm-card overflow-hidden">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-semibold text-slate-900">Recent health probes</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Latest read-only checks</p>
                    </div>

                    @if ($recentChecks->isEmpty())
                        <p class="px-5 py-6 text-sm text-slate-500">No health checks recorded yet.</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach ($recentChecks as $check)
                                <li class="flex items-start justify-between gap-3 px-5 py-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-900">{{ $check->router?->name ?? 'Removed router' }}</p>
                                        <p class="text-xs text-slate-500">{{ $check->checked_at?->diffForHumans() }}</p>
                                    </div>
                                    <span @class([
                                        'mm-badge shrink-0',
                                        'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $check->status->isSuccess(),
                                        'bg-red-50 text-red-700 ring-red-600/20' => ! $check->status->isSuccess(),
                                    ])>{{ $check->status->label() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        </div>
    @endif
</div>
