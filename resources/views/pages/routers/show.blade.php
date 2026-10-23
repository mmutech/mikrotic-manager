<?php

use App\Enums\RouterJobStatus;
use App\Jobs\TestRouterConnectionJob;
use App\Models\Router;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RouterJobService;
use App\Services\RouterRegistryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Router')] class extends Component
{
    public Router $router;

    public string $selectedUserId = '';

    public string $new_api_username = '';

    public string $new_api_password = '';

    public string $new_api_password_confirmation = '';

    public function mount(Router $router): void
    {
        $this->authorize('view', $router);

        $this->router = $router;
        $this->new_api_username = $router->api_username;
    }

    public function test(RouterJobService $jobs, AuditLogger $audit): void
    {
        $this->authorize('test', $this->router);

        if ($jobs->pendingTestExists($this->router)) {
            session()->flash('error', 'A connection test is already queued or running for this router.');

            return;
        }

        $job = $jobs->create(
            router: $this->router,
            type: 'test_connection',
            actor: auth()->user(),
            idempotencyKey: 'router-test:'.$this->router->getKey(),
        );

        $audit->record(
            'router.test_queued',
            $this->router,
            metadata: ['job_uuid' => $job->uuid, 'source' => 'router_detail'],
            actor: auth()->user(),
        );

        TestRouterConnectionJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            actorId: auth()->id(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        session()->flash('status', 'A read-only connection test has been queued.');
    }

    public function toggleMonitoring(RouterRegistryService $registry): void
    {
        $this->authorize('manageMonitoring', $this->router);

        $enabled = ! $this->router->monitoring_enabled;

        $registry->setMonitoring($this->router, $enabled, auth()->user());
        $this->router->refresh();

        session()->flash('status', $enabled
            ? 'Health monitoring enabled. The next scheduled run will probe this router.'
            : 'Health monitoring disabled. Existing history has been retained.');
    }

    public function grantAccess(RouterRegistryService $registry): void
    {
        $this->authorize('grantAccess', $this->router);

        $this->validate([
            'selectedUserId' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'active'),
            ],
        ], [
            'selectedUserId.required' => 'Choose a staff member to grant access to.',
        ]);

        /** @var \App\Models\User $user */
        $user = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'network-admin', 'support', 'finance']))
            ->findOrFail((int) $this->selectedUserId);

        if ($this->router->users()->whereKey($user->getKey())->exists()) {
            session()->flash('error', $user->name.' already has access to this router.');

            return;
        }

        $registry->grantAccess($this->router, $user, auth()->user());
        $this->router->load('users');
        $this->reset('selectedUserId');

        session()->flash('status', 'Access granted to '.$user->name.'.');
    }

    public function revokeAccess(int $userId, RouterRegistryService $registry): void
    {
        $this->authorize('revokeAccess', $this->router);

        /** @var \App\Models\User $user */
        $user = $this->router->users()->whereKey($userId)->firstOrFail();

        $registry->revokeAccess($this->router, $user, auth()->user());
        $this->router->load('users');

        session()->flash('status', 'Access revoked for '.$user->name.'.');
    }

    public function rotateCredentials(
        RouterRegistryService $registry,
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);

        $validated = $this->validate([
            'new_api_username' => ['required', 'string', 'max:120'],
            'new_api_password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $registry->update($this->router, $validated, auth()->user());
        $this->router->refresh();

        $job = $jobs->create(
            router: $this->router,
            type: 'test_connection',
            actor: auth()->user(),
            idempotencyKey: 'router-test:'.$this->router->getKey(),
        );

        $audit->record(
            'router.test_queued',
            $this->router,
            metadata: ['job_uuid' => $job->uuid, 'source' => 'credential_rotation'],
            actor: auth()->user(),
        );

        TestRouterConnectionJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            actorId: auth()->id(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        $this->reset('new_api_password', 'new_api_password_confirmation');

        session()->flash('status', 'Router credentials rotated. A read-only test has been queued.');
    }

    public function archive(RouterRegistryService $registry): void
    {
        $this->authorize('delete', $this->router);

        $name = $this->router->name;
        $registry->archive($this->router, auth()->user());

        session()->flash('status', $name.' has been archived and removed from active monitoring.');

        $this->redirectRoute('routers.index', navigate: true);
    }

    public function render()
    {
        $user = auth()->user();
        $router = $this->router;
        $router->load('users');

        $pendingJob = $router->jobs()
            ->whereIn('status', [
                RouterJobStatus::Queued->value,
                RouterJobStatus::Running->value,
                RouterJobStatus::Retrying->value,
            ])
            ->latest('created_at')
            ->first();

        $staff = $user->can('grantAccess', $router)
            ? User::query()
                ->where('status', 'active')
                ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'network-admin', 'support', 'finance']))
                ->whereKeyNot($router->users->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : collect();

        return $this->view([
            'canTest' => $user->can('test', $router),
            'canEdit' => $user->can('update', $router),
            'canMonitor' => $user->can('manageMonitoring', $router),
            'canGrant' => $user->can('grantAccess', $router),
            'canRevoke' => $user->can('revokeAccess', $router),
            'canArchive' => $user->can('delete', $router),
            'pendingJob' => $pendingJob,
            'healthChecks' => $router->healthChecks()->latest('checked_at')->limit(8)->get(),
            'jobs' => $router->jobs()->latest('created_at')->limit(8)->get(),
            'apiLogs' => $router->apiLogs()->latest('created_at')->limit(8)->get(),
            'staff' => $staff,
            'pendingJobCount' => $router->pendingJobCount(),
            'usableVoucherCount' => $router->usableVoucherCount(),
            'provisioningSummary' => $router->provisioningSummary(),
        ])->title($router->name);
    }
};
?>

@php
    $metadata = $router->metadata ?? [];
    $uptime = isset($metadata['uptime_seconds']) ? (int) $metadata['uptime_seconds'] : null;
    $uptimeLabel = $uptime === null
        ? null
        : sprintf('%dd %02dh %02dm', intdiv($uptime, 86400), intdiv($uptime % 86400, 3600), intdiv($uptime % 3600, 60));
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('routers.index') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-slate-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            Back to routers
        </a>

        <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h1 class="truncate text-2xl font-bold tracking-tight text-slate-900">{{ $router->name }}</h1>
                    <span class="mm-badge {{ $router->status->badgeClasses() }}">{{ $router->status->label() }}</span>
                    @if ($router->monitoring_enabled)
                        <span class="mm-badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">Monitoring active</span>
                    @else
                        <span class="mm-badge bg-slate-100 text-slate-600 ring-slate-500/20">Monitoring off</span>
                    @endif
                </div>
                <p class="mt-1.5 text-sm text-slate-500">
                    {{ $router->host }}:{{ $router->rest_port }} · {{ $router->isTls() ? 'HTTPS' : 'HTTP (lab only)' }}
                    @if ($router->site) · {{ $router->site }} @endif
                    @if ($router->location) · {{ $router->location }} @endif
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    Public ID {{ $router->public_id }}
                    @if ($router->creator) · Registered by {{ $router->creator->name }} {{ $router->created_at?->diffForHumans() }} @endif
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($canTest)
                    <button
                        type="button"
                        wire:click="test"
                        wire:loading.attr="disabled"
                        wire:target="test"
                        @disabled($pendingJob !== null)
                        class="mm-btn-secondary"
                        title="{{ $pendingJob ? 'A probe is already queued' : 'Queue a read-only connection test' }}"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z" />
                        </svg>
                        Test connection
                    </button>
                @endif

                @if ($canEdit)
                    <a href="{{ route('routers.edit', $router) }}" class="mm-btn-secondary">Edit router</a>
                @endif

                @if ($canArchive)
                    <button
                        type="button"
                        wire:click="archive"
                        wire:confirm="Archive {{ $router->name }}? It will be removed from active monitoring. The record is retained and can be restored by an administrator."
                        wire:loading.attr="disabled"
                        wire:target="archive"
                        class="mm-btn-danger"
                    >
                        Archive
                    </button>
                @endif
            </div>
        </div>
    </div>

    @if ($pendingJob)
        <div class="flex items-start gap-3 rounded-2xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-700" role="status">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <span>
                A {{ $pendingJob->type }} job is {{ $pendingJob->status->label() }} since {{ $pendingJob->created_at?->diffForHumans() }}.
                @if ($pendingJob->status === RouterJobStatus::Queued)
                    A queue worker must be running for it to execute.
                @endif
            </span>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="mm-card overflow-hidden xl:col-span-2" aria-labelledby="router-facts-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="router-facts-heading" class="text-base font-semibold text-slate-900">Verified router facts</h2>
                <p class="mt-0.5 text-xs text-slate-500">Read-only data from the most recent successful probe</p>
            </div>
            <dl class="grid gap-5 px-5 py-5 sm:grid-cols-2 sm:px-6">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Identity</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->identity ?: 'Not read yet' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">RouterOS version</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->ros_version ?: 'Not read yet' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Board</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->board_name ?: 'Not read yet' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Uptime</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $uptimeLabel ?? 'Not available' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">CPU load</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ isset($metadata['cpu_load']) ? $metadata['cpu_load'].'%' : 'Not available' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Memory</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">
                        @if (isset($metadata['free_memory'], $metadata['total_memory']) && (int) $metadata['total_memory'] > 0)
                            {{ \Illuminate\Support\Number::fileSize((int) $metadata['free_memory'], precision: 1) }} free of
                            {{ \Illuminate\Support\Number::fileSize((int) $metadata['total_memory'], precision: 1) }}
                        @else
                            Not available
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Last checked</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->last_checked_at?->format('j M Y, H:i:s') ?? 'Never' }} <span class="text-xs font-normal text-slate-500">{{ $router->last_checked_at?->diffForHumans() }}</span></dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Latency</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->latency_ms !== null ? $router->latency_ms.' ms' : 'Not available' }}</dd>
                </div>
            </dl>

            @if ($router->last_error_category)
                <div class="border-t border-red-100 bg-red-50 px-5 py-4 text-sm text-red-800 sm:px-6">
                    <p class="font-semibold">{{ $router->last_error_category->label() }}</p>
                    @if ($router->last_error_message)
                        <p class="mt-1 break-words text-xs leading-5">{{ $router->last_error_message }}</p>
                    @endif
                    <p class="mt-1 text-xs">{{ $router->consecutive_failures }} consecutive failure{{ $router->consecutive_failures === 1 ? '' : 's' }}.</p>
                </div>
            @endif
        </section>

        <section class="space-y-6">
            <div class="mm-card overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-900">Credentials</h2>
                </div>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-slate-500">API user</dt>
                        <dd class="font-mono text-xs font-medium text-slate-900">{{ $router->api_username }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-slate-500">Password</dt>
                        <dd class="font-mono text-xs font-medium text-slate-900">••••••••••••</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-slate-500">Last rotated</dt>
                        <dd class="text-xs font-medium text-slate-900">{{ $router->api_password_rotated_at?->format('j M Y, H:i') ?? 'Unknown' }}</dd>
                    </div>
                </dl>
                <div class="border-t border-slate-100 px-5 py-3">
                    <p class="text-xs leading-5 text-slate-500">The password is encrypted at rest and cannot be read back, even by a super administrator.</p>
                </div>
            </div>

            <div class="mm-card overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-900">Monitoring</h2>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-slate-900">Scheduled health checks</p>
                            <p class="mt-0.5 text-xs text-slate-500">Every five minutes when enabled</p>
                        </div>
                        @if ($canMonitor)
                            <button
                                type="button"
                                wire:click="toggleMonitoring"
                                wire:loading.attr="disabled"
                                wire:target="toggleMonitoring"
                                @class([
                                    'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2',
                                    'bg-success-600' => $router->monitoring_enabled,
                                    'bg-slate-300' => ! $router->monitoring_enabled,
                                ])
                                role="switch"
                                aria-checked="{{ $router->monitoring_enabled ? 'true' : 'false' }}"
                                aria-label="Toggle health monitoring"
                            >
                                <span @class([
                                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition',
                                    'translate-x-5' => $router->monitoring_enabled,
                                    'translate-x-0' => ! $router->monitoring_enabled,
                                ])></span>
                            </button>
                        @else
                            <span class="text-sm font-semibold {{ $router->monitoring_enabled ? 'text-emerald-700' : 'text-slate-500' }}">{{ $router->monitoring_enabled ? 'On' : 'Off' }}</span>
                        @endif
                    </div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
                        {{ $pendingJobCount }} open job{{ $pendingJobCount === 1 ? '' : 's' }} ·
                        {{ $usableVoucherCount }} usable voucher{{ $usableVoucherCount === 1 ? '' : 's' }} ·
                        {{ $provisioningSummary }}
                    </div>
                </div>
            </div>
        </section>
    </div>

    @if ($canGrant || $canRevoke)
        <section class="mm-card overflow-hidden" aria-labelledby="access-heading">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 id="access-heading" class="text-base font-semibold text-slate-900">Explicit router access</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Only super administrators can add or remove access. Roles alone do not grant router visibility.</p>
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse ($router->users as $member)
                    <div class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $member->name }} @if ($member->id === auth()->id())<span class="text-xs font-normal text-slate-500">(you)</span>@endif</p>
                            <p class="truncate text-xs text-slate-500">{{ $member->email }} · {{ implode(', ', $member->roleLabels()) ?: 'No role' }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-500">Granted {{ $member->pivot?->created_at?->diffForHumans() ?? 'previously' }}</span>
                            @if ($canRevoke)
                                <button
                                    type="button"
                                    wire:click="revokeAccess({{ $member->getKey() }})"
                                    wire:confirm="Revoke {{ $member->name }}'s access to {{ $router->name }}?"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                                >
                                    Revoke
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-5 text-sm text-slate-500 sm:px-6">No explicit access assignments. Only super administrators can currently reach this router.</p>
                @endforelse
            </div>

            @if ($canGrant)
                <form wire:submit="grantAccess" class="border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label for="selectedUserId" class="mm-label">Grant access to staff member</label>
                            <select id="selectedUserId" wire:model="selectedUserId" class="mm-input">
                                <option value="">Choose a staff member</option>
                                @foreach ($staff as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }} — {{ $member->email }}</option>
                                @endforeach
                            </select>
                            @error('selectedUserId') <p class="mm-error">{{ $message }}</p> @enderror
                            @if ($staff->isEmpty())
                                <p class="mm-help">Every eligible staff member already has access.</p>
                            @endif
                        </div>
                        <button type="submit" class="mm-btn-primary" @disabled($staff->isEmpty())>Grant access</button>
                    </div>
                </form>
            @endif
        </section>
    @endif

    @if ($canEdit)
        <section class="mm-card overflow-hidden" aria-labelledby="rotate-heading">
            <details>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 sm:px-6">
                    <div>
                        <h2 id="rotate-heading" class="text-base font-semibold text-slate-900">Rotate router credentials</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Replace the API user or password. Existing values are never shown.</p>
                    </div>
                    <svg class="h-5 w-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </summary>

                <form wire:submit="rotateCredentials" class="space-y-5 border-t border-slate-200 px-5 py-5 sm:px-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="new_api_username" class="mm-label">RouterOS API user</label>
                            <input id="new_api_username" type="text" wire:model="new_api_username" required maxlength="120" autocomplete="off" class="mm-input font-mono">
                            @error('new_api_username') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="new_api_password" class="mm-label">New API password</label>
                            <input id="new_api_password" type="password" wire:model="new_api_password" required autocomplete="new-password" class="mm-input" placeholder="At least 8 characters">
                            @error('new_api_password') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="new_api_password_confirmation" class="mm-label">Confirm new password</label>
                            <input id="new_api_password_confirmation" type="password" wire:model="new_api_password_confirmation" required autocomplete="new-password" class="mm-input">
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="mm-btn-primary" wire:loading.attr="disabled" wire:target="rotateCredentials">
                            <span wire:loading.remove wire:target="rotateCredentials">Rotate and test</span>
                            <span wire:loading wire:target="rotateCredentials">Rotating…</span>
                        </button>
                    </div>
                </form>
            </details>
        </section>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="mm-card overflow-hidden" aria-labelledby="health-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="health-heading" class="text-base font-semibold text-slate-900">Health history</h2>
                <p class="mt-0.5 text-xs text-slate-500">Last eight read-only probes</p>
            </div>
            @if ($healthChecks->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500 sm:px-6">No health checks recorded. Queue a connection test to create the first record.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="mm-th">Result</th>
                                <th scope="col" class="mm-th">Latency</th>
                                <th scope="col" class="mm-th">Checked</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($healthChecks as $check)
                                <tr>
                                    <td class="mm-td">
                                        <span @class([
                                            'mm-badge',
                                            'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $check->status->isSuccess(),
                                            'bg-red-50 text-red-700 ring-red-600/20' => ! $check->status->isSuccess(),
                                        ])>{{ $check->status->label() }}</span>
                                        @if ($check->error_category)
                                            <p class="mt-1 text-xs text-red-600">{{ $check->error_category }}</p>
                                        @endif
                                    </td>
                                    <td class="mm-td">{{ $check->latency_ms !== null ? $check->latency_ms.' ms' : '—' }}</td>
                                    <td class="mm-td text-xs text-slate-500">{{ $check->checked_at?->format('j M H:i:s') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="mm-card overflow-hidden" aria-labelledby="jobs-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="jobs-heading" class="text-base font-semibold text-slate-900">Router jobs</h2>
                <p class="mt-0.5 text-xs text-slate-500">Queued operations and their outcomes</p>
            </div>
            @if ($jobs->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500 sm:px-6">No router jobs yet.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($jobs as $job)
                        <li class="flex items-start justify-between gap-3 px-5 py-3 sm:px-6">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ str($job->type)->replace('_', ' ')->title() }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $job->created_at?->format('j M Y, H:i:s') }}
                                    @if ($job->attempts > 0) · {{ $job->attempts }} attempt{{ $job->attempts === 1 ? '' : 's' }} @endif
                                </p>
                                @if ($job->error_message)
                                    <p class="mt-1 break-words text-xs text-red-600">{{ $job->error_message }}</p>
                                @endif
                            </div>
                            <span @class([
                                'mm-badge shrink-0',
                                'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $job->status === RouterJobStatus::Succeeded,
                                'bg-red-50 text-red-700 ring-red-600/20' => $job->status === RouterJobStatus::Failed,
                                'bg-brand-50 text-brand-700 ring-brand-600/20' => $job->status->isPending(),
                                'bg-slate-100 text-slate-600 ring-slate-500/20' => $job->status === RouterJobStatus::Cancelled,
                            ])>{{ $job->status->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="mm-card overflow-hidden" aria-labelledby="api-log-heading">
        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
            <h2 id="api-log-heading" class="text-base font-semibold text-slate-900">Recent RouterOS API calls</h2>
            <p class="mt-0.5 text-xs text-slate-500">Endpoint, outcome, and latency only. Request and response bodies are not displayed.</p>
        </div>
        @if ($apiLogs->isEmpty())
            <p class="px-5 py-6 text-sm text-slate-500 sm:px-6">No API calls recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="mm-th">Endpoint</th>
                            <th scope="col" class="mm-th">HTTP</th>
                            <th scope="col" class="mm-th">Latency</th>
                            <th scope="col" class="mm-th">Result</th>
                            <th scope="col" class="mm-th">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($apiLogs as $log)
                            <tr>
                                <td class="mm-td"><span class="font-mono text-xs text-slate-700">{{ $log->method }} {{ $log->endpoint }}</span></td>
                                <td class="mm-td text-xs text-slate-600">{{ $log->http_status ?? '—' }}</td>
                                <td class="mm-td text-xs text-slate-600">{{ $log->latency_ms }} ms</td>
                                <td class="mm-td">
                                    <span @class([
                                        'mm-badge',
                                        'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $log->success,
                                        'bg-red-50 text-red-700 ring-red-600/20' => ! $log->success,
                                    ])>{{ $log->success ? 'Success' : ($log->error_category ? str($log->error_category)->replace('_', ' ')->title() : 'Failed') }}</span>
                                    @if ($log->error_message)
                                        <p class="mt-1 max-w-xs truncate text-xs text-red-600" title="{{ $log->error_message }}">{{ $log->error_message }}</p>
                                    @endif
                                </td>
                                <td class="mm-td text-xs text-slate-500">{{ $log->created_at?->format('j M H:i:s') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
