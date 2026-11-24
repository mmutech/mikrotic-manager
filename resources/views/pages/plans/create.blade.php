<?php

use App\Contracts\RouterGateway;
use App\Exceptions\RouterProbeException;
use App\Models\Plan;
use App\Models\Router;
use App\Services\AuditLogger;
use App\Support\CorrelationContext;
use App\Support\HotspotRateLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Create plan')] class extends Component
{
    public string $name = '';

    public string $router_id = '';

    public string $hotspot_profile = '';

    /** @var array<int, array{name: string, rate_limit: ?string, shared_users: string}> */
    public array $hotspotProfiles = [];

    public bool $hotspotProfilesLoaded = false;

    public string $description = '';

    public string $price = '0.00';

    public string $currency = 'NGN';

    public string $data_allowance_mb = '';

    public bool $is_unlimited = false;

    public string $validity_days = '';

    public string $time_allowance_seconds = '';

    public string $upload_speed_kbps = '';

    public string $download_speed_kbps = '';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('create', Plan::class);
    }

    public function updatedRouterId(): void
    {
        $this->hotspot_profile = '';
        $this->hotspotProfiles = [];
        $this->hotspotProfilesLoaded = false;
        $this->upload_speed_kbps = '';
        $this->download_speed_kbps = '';
        $this->resetErrorBag('hotspotProfiles');
        $this->resetErrorBag('hotspot_profile');
    }

    public function loadHotspotProfiles(RouterGateway $gateway, CorrelationContext $correlation): void
    {
        $this->authorize('create', Plan::class);
        $this->hotspotProfiles = [];
        $this->hotspotProfilesLoaded = false;
        $this->resetErrorBag('hotspotProfiles');

        if ($this->router_id === '') {
            return;
        }

        $router = Router::query()->visibleTo(auth()->user())->find($this->router_id);

        if ($router === null) {
            $this->addError('router_id', 'Select a router you are allowed to manage.');

            return;
        }

        try {
            $this->hotspotProfiles = $gateway->hotspotUserProfiles($router, $correlation->id());
            $this->hotspotProfilesLoaded = true;
        } catch (RouterProbeException $exception) {
            $this->addError('hotspotProfiles', $exception->getMessage());
        }
    }

    public function syncHotspotProfileSpeeds(): void
    {
        $this->authorize('create', Plan::class);
        $this->applySelectedProfileSpeeds();
    }

    private function applySelectedProfileSpeeds(): bool
    {
        $this->resetErrorBag('hotspot_profile');
        $profile = collect($this->hotspotProfiles)->firstWhere('name', $this->hotspot_profile);

        if ($profile === null) {
            $this->upload_speed_kbps = '';
            $this->download_speed_kbps = '';

            return true;
        }

        try {
            $speeds = HotspotRateLimit::speedsKbps($profile['rate_limit']);
        } catch (\InvalidArgumentException $exception) {
            $this->upload_speed_kbps = '';
            $this->download_speed_kbps = '';
            $this->addError('hotspot_profile', $exception->getMessage());

            return false;
        }

        $this->upload_speed_kbps = $speeds === null ? '' : (string) $speeds['upload'];
        $this->download_speed_kbps = $speeds === null ? '' : (string) $speeds['download'];

        return true;
    }

    private function generateCode(): string
    {
        do {
            $code = 'PLN-'.strtoupper(Str::random(8));
        } while (Plan::where('code', $code)->exists());

        return $code;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('create', Plan::class);

        if (! $this->applySelectedProfileSpeeds()) {
            return;
        }

        $user = auth()->user();
        $routerIds = $user->isSuperAdmin() ? Router::pluck('id')->all() : $user->routers()->pluck('routers.id')->all();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'router_id' => ['required', 'integer', Rule::in($routerIds)],
            'hotspot_profile' => [
                'required',
                'string',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/',
                Rule::in(array_column($this->hotspotProfiles, 'name')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'data_allowance_mb' => ['exclude_if:is_unlimited,true', 'required', 'integer', 'min:1'],
            'is_unlimited' => ['boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'time_allowance_seconds' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'upload_speed_kbps' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'required_with:download_speed_kbps'],
            'download_speed_kbps' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'required_with:upload_speed_kbps'],
            'is_active' => ['boolean'],
        ]);

        $plan = DB::transaction(function () use ($validated): Plan {
            $plan = Plan::create([
                'router_id' => $validated['router_id'],
                'name' => $validated['name'],
                'code' => $this->generateCode(),
                'description' => $validated['description'] ?: null,
                'price' => $validated['price'],
                'currency' => strtoupper($validated['currency']),
                'is_unlimited' => $validated['is_unlimited'],
                'data_allowance_bytes' => $validated['is_unlimited'] ? null : ((int) $validated['data_allowance_mb'] * 1024 * 1024),
                'validity_seconds' => ! empty($validated['validity_days']) ? (int) $validated['validity_days'] * 86400 : null,
                'time_allowance_seconds' => $validated['time_allowance_seconds'] !== null ? (int) $validated['time_allowance_seconds'] : null,
                'upload_speed_kbps' => filled($validated['upload_speed_kbps']) ? (int) $validated['upload_speed_kbps'] : null,
                'download_speed_kbps' => filled($validated['download_speed_kbps']) ? (int) $validated['download_speed_kbps'] : null,
                'is_active' => $validated['is_active'],
                'created_by' => auth()->id(),
            ]);

            $plan->profileMappings()->create([
                'router_id' => $validated['router_id'],
                'hotspot_profile' => $validated['hotspot_profile'],
            ]);

            return $plan;
        });

        $after = $plan->fresh()->toArray();
        $after['hotspot_profile'] = $validated['hotspot_profile'];

        $audit->record('plan.created', $plan, after: $after, actor: auth()->user());

        session()->flash('status', 'Plan created successfully.');
        $this->redirectRoute('plans.show', ['plan' => $plan->public_id], navigate: true);
    }

    public function render()
    {
        $user = auth()->user();
        $routers = $user->isSuperAdmin() ? Router::orderBy('name')->get() : $user->routers()->orderBy('name')->get();

        return $this->view([
            'routers' => $routers,
        ])->title('Create plan');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('plans.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to plans</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Create Plan</h1>
    </div>

    <form wire:submit="save" class="mm-card space-y-5 p-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mm-label">Plan name *</label>
                <input type="text" wire:model="name" class="mm-input">
                @error('name') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Router *</label>
                <select wire:model="router_id" wire:change="loadHotspotProfiles" class="mm-input">
                    <option value="">Select router</option>
                    @foreach ($routers as $r)
                        <option value="{{ $r->id }}">{{ $r->name }} ({{ $r->host }})</option>
                    @endforeach
                </select>
                @error('router_id') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Router HotSpot user profile *</label>
                <select wire:model="hotspot_profile" wire:change="syncHotspotProfileSpeeds" required class="mm-input" @disabled(! $hotspotProfilesLoaded)>
                    <option value="">Select a profile</option>
                    @foreach ($hotspotProfiles as $profile)
                        <option value="{{ $profile['name'] }}">
                            {{ $profile['name'] }}
                            · {{ $profile['rate_limit'] ?? 'no rate limit' }}
                            · {{ $profile['shared_users'] }} shared user(s)
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">The same RouterOS profile can be selected for multiple plans.</p>
                <p wire:loading wire:target="loadHotspotProfiles" class="mt-1 text-xs text-slate-500">Loading profiles from router…</p>
                @if ($hotspotProfilesLoaded && $hotspotProfiles === [])
                    <p class="mt-1 text-xs text-slate-500">No HotSpot user profiles were found on this router.</p>
                @endif
                @error('hotspotProfiles') <p class="mm-error">{{ $message }}</p> @enderror
                @error('hotspot_profile') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <p class="text-xs text-slate-500">Plan code is generated automatically.</p>

        <div>
            <label class="mm-label">Description</label>
            <textarea wire:model="description" rows="3" class="mm-input"></textarea>
            @error('description') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mm-label">Price *</label>
                <input type="number" step="0.01" wire:model="price" class="mm-input">
                @error('price') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Currency *</label>
                <input type="text" wire:model="currency" class="mm-input" maxlength="3">
                @error('currency') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Validity (days)</label>
                <input type="number" wire:model="validity_days" class="mm-input">
                @error('validity_days') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mm-label">Maximum connected time (seconds)</label>
                <input type="number" wire:model="time_allowance_seconds" min="1" class="mm-input">
                @error('time_allowance_seconds') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Upload limit (kbps)</label>
                <input type="number" wire:model="upload_speed_kbps" min="1" readonly class="mm-input bg-slate-50">
                @error('upload_speed_kbps') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Download limit (kbps)</label>
                <input type="number" wire:model="download_speed_kbps" min="1" readonly class="mm-input bg-slate-50">
                @error('download_speed_kbps') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <p class="text-xs text-slate-500">Speed limits are read from the selected RouterOS profile. This does not change the router.</p>
        </div>

        <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.live="is_unlimited" id="unlimited" class="rounded border-slate-300">
            <label for="unlimited" class="text-sm text-slate-700">Unlimited data</label>
        </div>

        @unless ($is_unlimited)
            <div>
                <label class="mm-label">Data allowance (MB) *</label>
                <input type="number" wire:model="data_allowance_mb" min="1" class="mm-input">
                @error('data_allowance_mb') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
        @endunless

        <div class="flex items-center gap-2">
            <input type="checkbox" wire:model="is_active" id="active" class="rounded border-slate-300">
            <label for="active" class="text-sm text-slate-700">Active</label>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('plans.index') }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Create plan</button>
        </div>
    </form>
</div>
