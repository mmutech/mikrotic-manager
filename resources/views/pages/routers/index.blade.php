<?php

use App\Enums\RouterStatus;
use App\Jobs\TestRouterConnectionJob;
use App\Models\Router;
use App\Services\AuditLogger;
use App\Services\RouterJobService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Routers')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $monitoring = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    public string $actionError = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedMonitoring(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'monitoring', 'sort', 'actionError');
        $this->resetPage();
    }

    public function test(int $routerId, RouterJobService $jobs, AuditLogger $audit): void
    {
        $this->actionError = '';

        $router = Router::query()->visibleTo(auth()->user())->findOrFail($routerId);

        $this->authorize('test', $router);

        if ($jobs->pendingTestExists($router)) {
            $this->actionError = 'A connection test for '.$router->name.' is already queued or running.';

            return;
        }

        $job = $jobs->create(
            router: $router,
            type: 'test_connection',
            actor: auth()->user(),
            idempotencyKey: 'router-test:'.$router->getKey(),
        );

        $audit->record(
            'router.test_queued',
            $router,
            metadata: ['job_uuid' => $job->uuid, 'source' => 'router_index'],
            actor: auth()->user(),
        );

        TestRouterConnectionJob::dispatch(
            routerId: $router->getKey(),
            routerJobId: $job->getKey(),
            actorId: auth()->id(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        session()->flash('status', 'A read-only connection test for '.$router->name.' has been queued.');
    }

    public function render()
    {
        $user = auth()->user();

        $query = Router::query()->visibleTo($user);

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';

            $query->where(function ($query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('host', 'like', $term)
                    ->orWhere('site', 'like', $term)
                    ->orWhere('location', 'like', $term)
                    ->orWhere('identity', 'like', $term);
            });
        }

        if ($this->status !== '' && RouterStatus::tryFrom($this->status) !== null) {
            $query->where('status', $this->status);
        }

        if ($this->monitoring === 'on') {
            $query->where('monitoring_enabled', true);
        } elseif ($this->monitoring === 'off') {
            $query->where('monitoring_enabled', false);
        }

        $query = match ($this->sort) {
            'checked' => $query->orderByRaw('last_checked_at IS NULL')->orderByDesc('last_checked_at'),
            'status' => $query->orderBy('status')->orderBy('name'),
            'added' => $query->orderByDesc('created_at'),
            default => $query->orderBy('name'),
        };

        $summaryQuery = Router::query()->visibleTo($user);

        return $this->view([
            'routers' => $query->paginate(12),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'online' => (clone $summaryQuery)->where('status', RouterStatus::Online->value)->count(),
                'attention' => (clone $summaryQuery)->whereIn('status', [RouterStatus::Offline->value, RouterStatus::Error->value])->count(),
                'monitoring' => (clone $summaryQuery)->monitoring()->count(),
            ],
            'statusOptions' => collect(RouterStatus::cases())
                ->reject(fn (RouterStatus $status) => $status === RouterStatus::Archived)
                ->mapWithKeys(fn (RouterStatus $status) => [$status->value => $status->label()])
                ->all(),
            'canCreate' => $user->can('create', Router::class),
            'hasFilters' => $this->search !== '' || $this->status !== '' || $this->monitoring !== '' || $this->sort !== 'name',
        ])->title('Routers');
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Network inventory</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Routers</h1>
            <p class="mt-1 text-sm text-slate-500">Register RouterOS 7 devices, verify connectivity, and monitor health without exposing credentials.</p>
        </div>

        @if ($canCreate)
            <a href="{{ route('routers.create') }}" class="mm-btn-primary">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Register router
            </a>
        @endif
    </div>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Router summary">
        <div class="mm-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Visible to you</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($summary['total']) }}</p>
        </div>
        <div class="mm-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Online</p>
            <p class="mt-2 text-2xl font-bold text-emerald-700">{{ number_format($summary['online']) }}</p>
        </div>
        <div class="mm-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Needs attention</p>
            <p class="mt-2 text-2xl font-bold text-red-700">{{ number_format($summary['attention']) }}</p>
        </div>
        <div class="mm-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Monitoring enabled</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($summary['monitoring']) }}</p>
        </div>
    </section>

    @if ($actionError !== '')
        <div class="rounded-2xl border border-amber-200 bg-warning-50 px-4 py-3 text-sm text-amber-800" role="alert">
            {{ $actionError }}
        </div>
    @endif

    <section class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4 sm:p-5">
            <div class="grid gap-3 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <label for="router-search" class="sr-only">Search routers</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                            </svg>
                        </span>
                        <input id="router-search" type="search" wire:model.live.debounce.300ms="search" class="mm-input pl-10" placeholder="Search name, host, site, identity…">
                    </div>
                </div>

                <div class="lg:col-span-3">
                    <label for="router-status" class="sr-only">Filter by status</label>
                    <select id="router-status" wire:model.live="status" class="mm-input">
                        <option value="">All statuses</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="lg:col-span-2">
                    <label for="router-monitoring" class="sr-only">Filter by monitoring</label>
                    <select id="router-monitoring" wire:model.live="monitoring" class="mm-input">
                        <option value="">Any monitoring</option>
                        <option value="on">Monitoring on</option>
                        <option value="off">Monitoring off</option>
                    </select>
                </div>

                <div class="lg:col-span-2">
                    <label for="router-sort" class="sr-only">Sort routers</label>
                    <select id="router-sort" wire:model.live="sort" class="mm-input">
                        <option value="name">Name A–Z</option>
                        <option value="checked">Recently checked</option>
                        <option value="status">Status</option>
                        <option value="added">Newest added</option>
                    </select>
                </div>
            </div>

            @if ($hasFilters)
                <div class="mt-3 flex items-center justify-between gap-3">
                    <p class="text-xs text-slate-500">Filters are applied to the routers you are allowed to access.</p>
                    <button type="button" wire:click="clearFilters" class="text-xs font-semibold text-brand-600 hover:text-brand-700">Clear filters</button>
                </div>
            @endif
        </div>

        @if ($routers->isEmpty())
            <div class="px-6 py-14 text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M2.454 8.893c5.249-5.25 13.843-5.25 19.092 0" />
                        <circle cx="12" cy="19" r="1.25" fill="currentColor" stroke="none" />
                    </svg>
                </span>
                <h2 class="mt-3 text-sm font-semibold text-slate-900">{{ $hasFilters ? 'No routers match these filters' : 'No routers registered yet' }}</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    {{ $hasFilters ? 'Adjust or clear the filters to see more of your inventory.' : 'Register a RouterOS 7 device to queue a read-only connection test.' }}
                </p>
                @if ($hasFilters)
                    <button type="button" wire:click="clearFilters" class="mm-btn-secondary mt-5">Clear filters</button>
                @elseif ($canCreate)
                    <a href="{{ route('routers.create') }}" class="mm-btn-primary mt-5">Register your first router</a>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="mm-th">Router</th>
                            <th scope="col" class="mm-th">Endpoint</th>
                            <th scope="col" class="mm-th">Status</th>
                            <th scope="col" class="mm-th">Last check</th>
                            <th scope="col" class="mm-th">Monitoring</th>
                            <th scope="col" class="mm-th text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($routers as $router)
                            <tr wire:key="router-row-{{ $router->public_id }}" class="hover:bg-slate-50/70">
                                <td class="mm-td">
                                    <a href="{{ route('routers.show', $router) }}" class="font-semibold text-slate-900 hover:text-brand-700">{{ $router->name }}</a>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ $router->identity ?: 'Identity not read yet' }}
                                        @if ($router->site) · {{ $router->site }} @endif
                                    </p>
                                </td>
                                <td class="mm-td">
                                    <span class="font-mono text-xs text-slate-700">{{ $router->host }}</span>
                                    <span class="text-xs text-slate-400">:{{ $router->rest_port }}</span>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $router->isTls() ? 'HTTPS' : 'HTTP (lab only)' }}</p>
                                </td>
                                <td class="mm-td">
                                    <span class="mm-badge {{ $router->status->badgeClasses() }}">{{ $router->status->label() }}</span>
                                    @if ($router->latency_ms !== null)
                                        <p class="mt-1 text-xs text-slate-500">{{ $router->latency_ms }} ms</p>
                                    @endif
                                </td>
                                <td class="mm-td">
                                    <span class="text-xs text-slate-600">{{ $router->last_checked_at?->diffForHumans() ?? 'Never' }}</span>
                                    @if ($router->last_error_category)
                                        <p class="mt-0.5 text-xs text-red-600">{{ $router->last_error_category->label() }}</p>
                                    @endif
                                </td>
                                <td class="mm-td">
                                    @if ($router->monitoring_enabled && $router->status !== RouterStatus::Disabled)
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-success-600"></span>
                                            Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500">
                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                            Off
                                        </span>
                                    @endif
                                </td>
                                <td class="mm-td">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('test', $router)
                                            <button
                                                type="button"
                                                wire:click="test({{ $router->getKey() }})"
                                                wire:loading.attr="disabled"
                                                wire:target="test({{ $router->getKey() }})"
                                                class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                                                title="Queue a read-only connection test"
                                            >
                                                Test
                                            </button>
                                        @endcan
                                        <a href="{{ route('routers.show', $router) }}" class="rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Open</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($routers->hasPages())
                <div class="border-t border-slate-200 px-4 py-3 sm:px-5">
                    {{ $routers->links() }}
                </div>
            @endif
        @endif
    </section>
</div>
