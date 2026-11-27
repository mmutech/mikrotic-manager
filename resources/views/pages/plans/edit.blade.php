<?php

use App\Contracts\RouterGateway;
use App\Enums\ProvisioningStatus;
use App\Exceptions\RouterProbeException;
use App\Models\Plan;
use App\Models\Router;
use App\Services\AuditLogger;
use App\Support\CorrelationContext;
use App\Support\HotspotRateLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Edit plan')] class extends Component
{
    public Plan $plan;

    public string $name = '';

    public string $hotspot_profile = '';

    /** @var array<int, array{name: string, rate_limit: ?string, shared_users: string}> */
    public array $hotspotProfiles = [];

    public bool $hotspotProfilesLoaded = false;

    public string $description = '';

    public string $price = '';

    public string $currency = '';

    public string $data_allowance_mb = '';

    public bool $is_unlimited = false;

    public string $validity_days = '';

    public string $time_allowance_seconds = '';

    public string $upload_speed_kbps = '';

    public string $download_speed_kbps = '';

    public bool $is_active = true;

    public function mount(Plan $plan): void
    {
        $this->authorize('update', $plan);
        $this->plan = $plan;
        $this->name = $plan->name;
        $this->hotspot_profile = (string) $plan->profileMappings()
            ->where('router_id', $plan->router_id)
            ->value('hotspot_profile');
        $this->description = (string) $plan->description;
        $this->price = (string) $plan->price;
        $this->currency = $plan->currency;
        $this->is_unlimited = (bool) $plan->is_unlimited;
        $this->is_active = (bool) $plan->is_active;
        $this->data_allowance_mb = $plan->data_allowance_bytes ? (string) ((int) $plan->data_allowance_bytes / 1024 / 1024) : '';
        $this->validity_days = $plan->validity_seconds ? (string) ((int) $plan->validity_seconds / 86400) : '';
        $this->time_allowance_seconds = $plan->time_allowance_seconds === null
            ? '0'
            : (string) $plan->time_allowance_seconds;
        $this->upload_speed_kbps = $plan->upload_speed_kbps ? (string) $plan->upload_speed_kbps : '';
        $this->download_speed_kbps = $plan->download_speed_kbps ? (string) $plan->download_speed_kbps : '';
    }

    public function loadHotspotProfiles(RouterGateway $gateway, CorrelationContext $correlation): void
    {
        $this->authorize('update', $this->plan);
        $this->hotspotProfiles = [];
        $this->hotspotProfilesLoaded = false;
        $this->resetErrorBag('hotspotProfiles');

        $router = Router::query()->visibleTo(auth()->user())->find($this->plan->router_id);

        if ($router === null) {
            $this->addError('hotspotProfiles', 'The router for this plan is unavailable.');

            return;
        }

        try {
            $this->hotspotProfiles = $gateway->hotspotUserProfiles($router, $correlation->id());
            $this->hotspotProfilesLoaded = true;
        } catch (RouterProbeException $exception) {
            $this->addError('hotspotProfiles', $exception->getMessage());

            return;
        }

        if ($this->hotspot_profile !== '' && ! in_array($this->hotspot_profile, array_column($this->hotspotProfiles, 'name'), true)) {
            $this->hotspot_profile = '';
            $this->upload_speed_kbps = '';
            $this->download_speed_kbps = '';
            $this->addError('hotspot_profile', 'The currently mapped profile is not available. Select a profile from the refreshed list.');
        } else {
            $this->applySelectedProfileSpeeds();
        }
    }

    public function syncHotspotProfileSpeeds(): void
    {
        $this->authorize('update', $this->plan);
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

    public function save(AuditLogger $audit): void
    {
        $this->authorize('update', $this->plan);

        if (! $this->applySelectedProfileSpeeds()) {
            return;
        }

        $profileMapping = $this->plan->profileMappings()
            ->where('router_id', $this->plan->router_id)
            ->first();
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
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
            'time_allowance_seconds' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'upload_speed_kbps' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'required_with:download_speed_kbps'],
            'download_speed_kbps' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'required_with:upload_speed_kbps'],
            'is_active' => ['boolean'],
        ]);

        $before = $this->plan->toArray();
        $before['hotspot_profile'] = $profileMapping?->hotspot_profile;

        DB::transaction(function () use ($validated): void {
            $this->plan->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?: null,
                'price' => $validated['price'],
                'currency' => strtoupper($validated['currency']),
                'is_unlimited' => $validated['is_unlimited'],
                'data_allowance_bytes' => $validated['is_unlimited'] ? null : ((int) $validated['data_allowance_mb'] * 1024 * 1024),
                'validity_seconds' => ! empty($validated['validity_days']) ? (int) $validated['validity_days'] * 86400 : null,
                'time_allowance_seconds' => (int) $validated['time_allowance_seconds'],
                'upload_speed_kbps' => filled($validated['upload_speed_kbps']) ? (int) $validated['upload_speed_kbps'] : null,
                'download_speed_kbps' => filled($validated['download_speed_kbps']) ? (int) $validated['download_speed_kbps'] : null,
                'is_active' => $validated['is_active'],
            ]);

            $this->plan->profileMappings()->updateOrCreate(
                ['router_id' => $this->plan->router_id],
                ['hotspot_profile' => $validated['hotspot_profile']],
            );

            $this->plan->vouchers()
                ->whereIn('provisioning_status', [
                    ProvisioningStatus::Pending->value,
                    ProvisioningStatus::Failed->value,
                ])
                ->whereNull('provisioned_at')
                ->update([
                    'hotspot_profile' => $validated['hotspot_profile'],
                    'upload_speed_kbps' => filled($validated['upload_speed_kbps'])
                        ? (int) $validated['upload_speed_kbps']
                        : null,
                    'download_speed_kbps' => filled($validated['download_speed_kbps'])
                        ? (int) $validated['download_speed_kbps']
                        : null,
                ]);
        });

        $after = $this->plan->fresh()->toArray();
        $after['hotspot_profile'] = $validated['hotspot_profile'];

        $audit->record('plan.updated', $this->plan, before: $before, after: $after, actor: auth()->user());

        session()->flash('status', 'Plan updated successfully.');
        $this->redirectRoute('plans.show', ['plan' => $this->plan->public_id], navigate: true);
    }

    public function render()
    {
        return $this->view([
            'timeAllowanceOptions' => Plan::connectedTimeOptions(),
        ])->title('Edit plan');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('plans.show', $plan) }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to plan</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Edit Plan</h1>
    </div>

    <form wire:submit="save" class="mm-card space-y-5 p-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mm-label">Plan name *</label>
                <input type="text" wire:model="name" class="mm-input">
                @error('name') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Plan code</label>
                <input type="text" value="{{ $plan->code }}" class="mm-input bg-slate-50" disabled>
                <p class="mt-1 text-xs text-slate-500">Codes are generated automatically.</p>
            </div>
            <div class="sm:col-span-2">
                <label class="mm-label">Router HotSpot user profile *</label>
                <div class="flex flex-col gap-2 sm:flex-row">
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
                    <button
                        type="button"
                        wire:click="loadHotspotProfiles"
                        wire:loading.attr="disabled"
                        wire:target="loadHotspotProfiles"
                        class="mm-btn-secondary shrink-0"
                    >
                        <span wire:loading.remove wire:target="loadHotspotProfiles">Load profiles</span>
                        <span wire:loading wire:target="loadHotspotProfiles">Loading…</span>
                    </button>
                </div>
                <p class="mt-1 text-xs text-slate-500">The same RouterOS profile can be selected for multiple plans.</p>
                @if ($hotspotProfilesLoaded && $hotspotProfiles === [])
                    <p class="mt-1 text-xs text-slate-500">No HotSpot user profiles were found on this router.</p>
                @endif
                @error('hotspotProfiles') <p class="mm-error">{{ $message }}</p> @enderror
                @error('hotspot_profile') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
        </div>

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
                <select wire:model="time_allowance_seconds" class="mm-input">
                    @foreach ($timeAllowanceOptions as $value => $label)
                        <option value="{{ $value }}">{{ $value }} — {{ $label }}</option>
                    @endforeach
                    @if ($time_allowance_seconds !== '' && ! array_key_exists($time_allowance_seconds, $timeAllowanceOptions))
                        <option value="{{ $time_allowance_seconds }}">Custom limit — {{ $time_allowance_seconds }} second(s)</option>
                    @endif
                </select>
                @error('time_allowance_seconds') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Upload limit (kbps)</label>
                <input type="number" wire:model="upload_speed_kbps" min="1" readonly class="mm-input bg-slate-50" disabled>
                @error('upload_speed_kbps') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Download limit (kbps)</label>
                <input type="number" wire:model="download_speed_kbps" min="1" readonly class="mm-input bg-slate-50" disabled>
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
            <a href="{{ route('plans.show', $plan) }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Save changes</button>
        </div>
    </form>
</div>
