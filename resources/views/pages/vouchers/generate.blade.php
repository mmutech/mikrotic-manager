<?php

use App\Models\Plan;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Generate vouchers')] class extends Component
{
    public string $router_id = '';

    public string $plan_id = '';

    public int $quantity = 10;

    public string $prefix = '';

    public function mount(): void
    {
        $this->authorize('generate', Voucher::class);
    }

    public function updatedRouterId(): void
    {
        if (
            $this->plan_id !== ''
            && ! Plan::query()
                ->whereKey($this->plan_id)
                ->where('router_id', $this->router_id)
                ->exists()
        ) {
            $this->plan_id = '';
        }

        $this->resetErrorBag(['router_id', 'plan_id']);
    }

    public function updatedPlanId(): void
    {
        $this->resetErrorBag('plan_id');
    }

    public function generate(AuditLogger $audit): void
    {
        $this->authorize('generate', Voucher::class);

        $user = auth()->user();
        $routerIds = Router::query()->visibleTo($user)->pluck('routers.id')->all();

        $validated = $this->validate([
            'router_id' => ['required', 'integer', Rule::in($routerIds)],
            'plan_id' => ['required', 'exists:plans,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'prefix' => ['nullable', 'string', 'max:10', 'alpha_num'],
        ]);

        $router = Router::query()->visibleTo($user)->findOrFail($validated['router_id']);
        $plan = Plan::where('id', $validated['plan_id'])->where('router_id', $router->id)->where('is_active', true)->firstOrFail();
        $profileMapping = $plan->profileMappings()
            ->where('router_id', $router->id)
            ->first();

        if ($profileMapping === null) {
            $this->addError('plan_id', 'Map this plan to an existing RouterOS HotSpot user profile before generating vouchers.');

            return;
        }

        if (! $plan->is_unlimited && ($plan->data_allowance_bytes === null || $plan->data_allowance_bytes <= 0)) {
            $this->addError('plan_id', 'A finite plan data allowance must be greater than zero bytes.');

            return;
        }

        if ($plan->time_allowance_seconds !== null && $plan->time_allowance_seconds < 0) {
            $this->addError('plan_id', 'The plan connected-time allowance cannot be negative.');

            return;
        }

        if (($plan->upload_speed_kbps === null) !== ($plan->download_speed_kbps === null)) {
            $this->addError('plan_id', 'The plan must set both upload and download limits, or neither.');

            return;
        }

        $generated = 0;
        for ($i = 0; $i < $validated['quantity']; $i++) {
            $username = ($validated['prefix'] ? strtoupper($validated['prefix']).'-' : '').strtoupper(Str::random(6));

            $voucher = Voucher::create([
                'router_id' => $router->id,
                'plan_id' => $plan->id,
                'hotspot_profile' => $profileMapping->hotspot_profile,
                'generated_by' => auth()->id(),
                'username' => $username,
                'password' => $username,
                'data_allowance_bytes' => $plan->is_unlimited ? null : $plan->data_allowance_bytes,
                'time_allowance_seconds' => $plan->time_allowance_seconds,
                'upload_speed_kbps' => $plan->upload_speed_kbps,
                'download_speed_kbps' => $plan->download_speed_kbps,
                'validity_seconds' => $plan->validity_seconds,
                'validity_starts' => $plan->validity_starts,
                'expiry_policy' => $plan->expiry_policy,
                'lifecycle_status' => 'pending',
                'provisioning_status' => 'pending',
            ]);

            $generated++;
        }

        $audit->record('vouchers.generated', $router, metadata: ['count' => $generated, 'plan_id' => $plan->id], actor: auth()->user());

        session()->flash('status', "Generated {$generated} voucher(s).");
        $this->redirectRoute('vouchers.index', navigate: true);
    }

    public function render()
    {
        $user = auth()->user();
        $visibleRouterIds = Router::query()->visibleTo($user)->pluck('routers.id');

        $routers = Router::query()->visibleTo($user)->orderBy('name')->get();
        $plans = Plan::query()
            ->with('profileMappings')
            ->where('is_active', true)
            ->whereIn('router_id', $visibleRouterIds);

        if ($this->router_id !== '' && $visibleRouterIds->contains((int) $this->router_id)) {
            $plans->where('router_id', $this->router_id);
        }

        $plans = $plans->orderBy('name')->get();

        return $this->view([
            'routers' => $routers,
            'plans' => $plans,
        ])->title('Generate vouchers');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('vouchers.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to vouchers</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Generate Vouchers</h1>
        <p class="mt-1 text-sm text-slate-500">Vouchers are created with separate lifecycle and provisioning status.</p>
    </div>

    <form wire:submit="generate" class="mm-card space-y-5 p-6">
        <div>
            <label class="mm-label">Router *</label>
            <select wire:model.live="router_id" class="mm-input">
                <option value="">Select router</option>
                @foreach ($routers as $r)
                    <option value="{{ $r->id }}">{{ $r->name }} ({{ $r->host }})</option>
                @endforeach
            </select>
            @error('router_id') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mm-label">Plan *</label>
            <select wire:model.live="plan_id" class="mm-input">
                <option value="">Select plan</option>
                @foreach ($plans as $p)
                    <option value="{{ $p->id }}">
                        {{ $p->name }} - {{ $p->currency }} {{ $p->price }}
                        ({{ $p->profileMappings->first()?->hotspot_profile ?? 'profile not mapped' }})
                    </option>
                @endforeach
            </select>
            @error('plan_id') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mm-label">Quantity *</label>
                <input type="number" wire:model="quantity" class="mm-input" min="1" max="100">
                @error('quantity') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Username prefix</label>
                <input type="text" wire:model="prefix" class="mm-input" placeholder="e.g., ACC">
                @error('prefix') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('vouchers.index') }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Generate vouchers</button>
        </div>
    </form>
</div>
