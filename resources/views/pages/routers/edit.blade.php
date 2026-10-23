<?php

use App\Jobs\TestRouterConnectionJob;
use App\Models\Router;
use App\Rules\SafeRouterHost;
use App\Services\AuditLogger;
use App\Services\RouterJobService;
use App\Services\RouterRegistryService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Edit router')] class extends Component
{
    public Router $router;

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

    public function mount(Router $router): void
    {
        $this->authorize('update', $router);

        $this->router = $router;
        $this->name = $router->name;
        $this->location = (string) $router->location;
        $this->site = (string) $router->site;
        $this->host = $router->host;
        $this->rest_port = (string) $router->rest_port;
        $this->api_username = $router->api_username;
        $this->tls_verify = $router->tls_verify;
        $this->monitoring_enabled = $router->monitoring_enabled;
        $this->notes = (string) $router->notes;
    }

    public function save(
        RouterRegistryService $registry,
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);

        $originalHost = $this->router->host;
        $originalPort = (int) $this->router->rest_port;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'site' => ['nullable', 'string', 'max:80'],
            'host' => ['required', 'string', 'max:253', new SafeRouterHost],
            'rest_port' => ['required', 'integer', Rule::in((array) config('mikrotik.allowed_ports', [443, 8729]))],
            'api_username' => ['required', 'string', 'max:120'],
            'api_password' => ['nullable', 'string', 'min:8', 'max:255'],
            'tls_verify' => ['boolean'],
            'monitoring_enabled' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'rest_port.in' => 'Choose an approved RouterOS REST port.',
        ]);

        if ((int) $validated['rest_port'] === 80 && ! config('mikrotik.allow_http', false)) {
            $this->addError('rest_port', 'Plain HTTP is disabled. Use HTTPS on port 443 or 8729.');

            return;
        }

        /** @var \App\Models\User $actor */
        $actor = auth()->user();
        $endpointChanged = $validated['host'] !== $originalHost || (int) $validated['rest_port'] !== $originalPort;
        $passwordChanged = filled($validated['api_password'] ?? null);

        $registry->update($this->router, $validated, $actor);

        if ($this->monitoring_enabled || $endpointChanged || $passwordChanged) {
            $job = $jobs->create(
                router: $this->router,
                type: 'test_connection',
                actor: $actor,
                idempotencyKey: 'router-test:'.$this->router->getKey(),
            );

            $audit->record(
                'router.test_queued',
                $this->router,
                metadata: ['job_uuid' => $job->uuid, 'source' => 'router_update'],
                actor: $actor,
            );

            TestRouterConnectionJob::dispatch(
                routerId: $this->router->getKey(),
                routerJobId: $job->getKey(),
                actorId: $actor->getKey(),
                correlationId: $job->correlation_id,
            )->afterCommit();

            session()->flash('status', 'Router updated. A read-only connection test has been queued.');
        } else {
            session()->flash('status', 'Router updated.');
        }

        $this->redirectRoute('routers.show', ['router' => $this->router->public_id], navigate: true);
    }

    public function render()
    {
        return $this->view([
            'approvedPorts' => (array) config('mikrotik.allowed_ports', [443, 8729]),
            'allowHttp' => (bool) config('mikrotik.allow_http', false),
            'managementNetworks' => (array) config('mikrotik.management_networks', []),
            'tlsEnforced' => app()->environment('production'),
        ])->title('Edit '.$this->router->name);
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('routers.show', $router) }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-slate-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            Back to router
        </a>
        <p class="mt-4 text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Network inventory</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Edit {{ $router->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Changing the host, port, or credentials resets stale identity data and queues a fresh read-only probe.</p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <section class="mm-card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-900">Router details</h2>
            </div>
            <div class="grid gap-5 px-5 py-5 sm:grid-cols-2 sm:px-6">
                <div class="sm:col-span-2">
                    <label for="name" class="mm-label">Router name <span class="text-red-500">*</span></label>
                    <input id="name" type="text" wire:model="name" required maxlength="120" class="mm-input">
                    @error('name') <p class="mm-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="site" class="mm-label">Site</label>
                    <input id="site" type="text" wire:model="site" maxlength="80" class="mm-input">
                    @error('site') <p class="mm-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="location" class="mm-label">Location detail</label>
                    <input id="location" type="text" wire:model="location" maxlength="160" class="mm-input">
                    @error('location') <p class="mm-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="mm-card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-900">Management endpoint</h2>
                <p class="mt-1 text-sm text-slate-500">Only hosts inside the approved management ranges can be saved.</p>
            </div>
            <div class="grid gap-5 px-5 py-5 sm:grid-cols-6 sm:px-6">
                <div class="sm:col-span-4">
                    <label for="host" class="mm-label">Hostname or private IP <span class="text-red-500">*</span></label>
                    <input id="host" type="text" wire:model="host" required maxlength="253" class="mm-input font-mono">
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
                </div>

                <div class="sm:col-span-3">
                    <label for="api_username" class="mm-label">RouterOS API user <span class="text-red-500">*</span></label>
                    <input id="api_username" type="text" wire:model="api_username" required maxlength="120" autocomplete="off" class="mm-input font-mono">
                    @error('api_username') <p class="mm-error">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-3">
                    <label for="api_password" class="mm-label">New API password</label>
                    <input id="api_password" type="password" wire:model="api_password" autocomplete="new-password" class="mm-input" placeholder="Leave blank to keep current">
                    @error('api_password') <p class="mm-error">{{ $message }}</p> @enderror
                    <p class="mm-help">
                        Last rotated {{ $router->api_password_rotated_at?->diffForHumans() ?? 'at registration' }}. The existing password cannot be displayed.
                    </p>
                </div>

                <div class="sm:col-span-6">
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                        <input type="checkbox" wire:model="tls_verify" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Verify the router TLS certificate</span>
                            <span class="mt-0.5 block text-xs leading-5 text-slate-500">
                                {{ $tlsEnforced ? 'Production always enforces certificate verification.' : 'Disable only for a documented lab certificate. Production ignores this setting.' }}
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </section>

        <section class="mm-card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-900">Monitoring & notes</h2>
            </div>
            <div class="space-y-5 px-5 py-5 sm:px-6">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="monitoring_enabled" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                    <span>
                        <span class="block text-sm font-medium text-slate-800">Enable health monitoring</span>
                        <span class="mt-0.5 block text-xs leading-5 text-slate-500">Disabling monitoring stops scheduled probes but keeps the router record and history.</span>
                    </span>
                </label>

                <div>
                    <label for="notes" class="mm-label">Notes</label>
                    <textarea id="notes" wire:model="notes" rows="3" maxlength="2000" class="mm-input" placeholder="Optional operational notes. Do not store passwords here."></textarea>
                    @error('notes') <p class="mm-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('routers.show', $router) }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </form>
</div>
