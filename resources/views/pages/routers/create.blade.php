<?php

use App\Jobs\TestRouterConnectionJob;
use App\Models\Router;
use App\Models\User;
use App\Rules\SafeRouterHost;
use App\Services\AuditLogger;
use App\Services\RouterJobService;
use App\Services\RouterRegistryService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Register router')] class extends Component
{
    public string $name = '';

    public string $location = '';

    public string $site = '';

    public string $host = '';

    public string $rest_port = '443';

    public string $api_username = '';

    public string $api_password = '';

    public bool $tls_verify = true;

    public bool $monitoring_enabled = true;

    public string $notes = '';

    /** @var array<int, int|string> */
    public array $grantedUserIds = [];

    public function mount(): void
    {
        $this->authorize('create', Router::class);
    }

    public function save(
        RouterRegistryService $registry,
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('create', Router::class);

        $staffIds = $this->staffUsers()->pluck('id')->all();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'site' => ['nullable', 'string', 'max:80'],
            'host' => ['required', 'string', 'max:253', new SafeRouterHost],
            'rest_port' => ['required', 'integer', Rule::in((array) config('mikrotik.allowed_ports', [443, 8729]))],
            'api_username' => ['required', 'string', 'max:120'],
            'api_password' => ['required', 'string', 'min:8', 'max:255'],
            'tls_verify' => ['boolean'],
            'monitoring_enabled' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'grantedUserIds' => ['array'],
            'grantedUserIds.*' => ['integer', Rule::in($staffIds)],
        ], [
            'rest_port.in' => 'Choose an approved RouterOS REST port.',
        ]);

        if ((int) $validated['rest_port'] === 80 && ! config('mikrotik.allow_http', false)) {
            $this->addError('rest_port', 'Plain HTTP is disabled. Use HTTPS on port 443 or 8729.');

            return;
        }

        /** @var \App\Models\User $actor */
        $actor = auth()->user();
        $router = $registry->create($validated, $actor);

        if ($actor->isSuperAdmin()) {
            User::query()
                ->whereKey($this->grantedUserIds)
                ->where('status', 'active')
                ->get()
                ->each(fn (User $user) => $registry->grantAccess($router, $user, $actor));
        }

        $job = $jobs->create(
            router: $router,
            type: 'test_connection',
            actor: $actor,
            idempotencyKey: 'router-test:'.$router->getKey(),
        );

        $audit->record(
            'router.test_queued',
            $router,
            metadata: ['job_uuid' => $job->uuid, 'source' => 'router_create'],
            actor: $actor,
        );

        TestRouterConnectionJob::dispatch(
            routerId: $router->getKey(),
            routerJobId: $job->getKey(),
            actorId: $actor->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        session()->flash('status', 'Router registered. A read-only connection test has been queued.');

        $this->redirectRoute('routers.show', ['router' => $router->public_id], navigate: true);
    }

    public function render()
    {
        return $this->view([
            'staff' => auth()->user()->isSuperAdmin() ? $this->staffUsers() : collect(),
            'approvedPorts' => (array) config('mikrotik.allowed_ports', [443, 8729]),
            'allowHttp' => (bool) config('mikrotik.allow_http', false),
            'managementNetworks' => (array) config('mikrotik.management_networks', []),
            'tlsEnforced' => app()->environment('production'),
        ])->title('Register router');
    }

    private function staffUsers()
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'network-admin', 'support', 'finance']))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('routers.index') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-slate-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            Back to routers
        </a>
        <p class="mt-4 text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Network inventory</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Register a router</h1>
        <p class="mt-1 text-sm text-slate-500">Only read-only REST calls are made during registration. Nothing is written to the router.</p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <section class="mm-card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-900">Router details</h2>
                <p class="mt-1 text-sm text-slate-500">Identify the device and where it is physically located.</p>
            </div>
            <div class="grid gap-5 px-5 py-5 sm:grid-cols-2 sm:px-6">
                <div class="sm:col-span-2">
                    <label for="name" class="mm-label">Router name <span class="text-red-500">*</span></label>
                    <input id="name" type="text" wire:model="name" required maxlength="120" class="mm-input" placeholder="e.g. Accra HQ — Core Router">
                    @error('name') <p class="mm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="site" class="mm-label">Site</label>
                    <input id="site" type="text" wire:model="site" maxlength="80" class="mm-input" placeholder="e.g. Accra HQ">
                    @error('site') <p class="mm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="location" class="mm-label">Location detail</label>
                    <input id="location" type="text" wire:model="location" maxlength="160" class="mm-input" placeholder="e.g. Server room, rack B">
                    @error('location') <p class="mm-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="mm-card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-900">Management endpoint</h2>
                <p class="mt-1 text-sm text-slate-500">The host must resolve only to an approved management network. Public destinations are rejected.</p>
            </div>
            <div class="grid gap-5 px-5 py-5 sm:grid-cols-6 sm:px-6">
                <div class="sm:col-span-4">
                    <label for="host" class="mm-label">Hostname or private IP <span class="text-red-500">*</span></label>
                    <input id="host" type="text" wire:model="host" required maxlength="253" class="mm-input font-mono" placeholder="192.168.88.1">
                    @error('host') <p class="mm-error">{{ $message }}</p> @enderror
                    <p class="mm-help">Approved ranges: {{ implode(', ', $managementNetworks) }}</p>
                </div>

                <div class="sm:col-span-2">
                    <label for="rest_port" class="mm-label">REST port <span class="text-red-500">*</span></label>
                    <select id="rest_port" wire:model="rest_port" class="mm-input">
                        @foreach ($approvedPorts as $port)
                            <option value="{{ $port }}">{{ $port }}{{ in_array((int) $port, [443, 8729], true) ? ' (HTTPS)' : ' (HTTP)' }}</option>
                        @endforeach
                    </select>
                    @error('rest_port') <p class="mm-error">{{ $message }}</p> @enderror
                    @unless ($allowHttp)
                        <p class="mm-help">Plain HTTP is blocked in this environment.</p>
                    @endunless
                </div>

                <div class="sm:col-span-3">
                    <label for="api_username" class="mm-label">RouterOS API user <span class="text-red-500">*</span></label>
                    <input id="api_username" type="text" wire:model="api_username" required maxlength="120" autocomplete="off" class="mm-input font-mono" placeholder="mmutech-api">
                    @error('api_username') <p class="mm-error">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-3">
                    <label for="api_password" class="mm-label">RouterOS API password <span class="text-red-500">*</span></label>
                    <input id="api_password" type="password" wire:model="api_password" required autocomplete="new-password" class="mm-input" placeholder="At least 8 characters">
                    @error('api_password') <p class="mm-error">{{ $message }}</p> @enderror
                    <p class="mm-help">Encrypted at rest and never displayed again. You can only rotate it later.</p>
                </div>

                <div class="sm:col-span-6">
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                        <input type="checkbox" wire:model="tls_verify" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Verify the router TLS certificate</span>
                            <span class="mt-0.5 block text-xs leading-5 text-slate-500">
                                Keep this enabled. {{ $tlsEnforced ? 'Production always enforces certificate verification.' : 'Disabling is only appropriate for a documented lab certificate and is ignored in production.' }}
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </section>

        <section class="mm-card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-900">Monitoring & access</h2>
                <p class="mt-1 text-sm text-slate-500">Monitoring queues read-only health probes. Router access is scoped separately from your role.</p>
            </div>
            <div class="space-y-5 px-5 py-5 sm:px-6">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="monitoring_enabled" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                    <span>
                        <span class="block text-sm font-medium text-slate-800">Enable health monitoring</span>
                        <span class="mt-0.5 block text-xs leading-5 text-slate-500">The scheduler checks monitored routers every five minutes and records the result.</span>
                    </span>
                </label>

                @if ($staff->isNotEmpty())
                    <div>
                        <p class="mm-label">Grant access to staff</p>
                        <p class="mm-help mb-2">You receive access automatically. Additional users only see routers explicitly assigned to them.</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($staff as $member)
                                @if ($member->id !== auth()->id())
                                    <label class="flex items-center gap-2.5 rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-700 hover:bg-slate-50">
                                        <input type="checkbox" wire:model="grantedUserIds" value="{{ $member->id }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium">{{ $member->name }}</span>
                                            <span class="block truncate text-xs text-slate-500">{{ $member->email }}</span>
                                        </span>
                                    </label>
                                @endif
                            @endforeach
                        </div>
                        @error('grantedUserIds.*') <p class="mm-error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label for="notes" class="mm-label">Notes</label>
                    <textarea id="notes" wire:model="notes" rows="3" maxlength="2000" class="mm-input" placeholder="Optional operational notes. Do not store router passwords here."></textarea>
                    @error('notes') <p class="mm-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('routers.index') }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Register and test</span>
                <span wire:loading wire:target="save">Registering…</span>
            </button>
        </div>
    </form>
</div>
