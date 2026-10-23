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
        $this->plan_id = '';
    }

    public function generate(AuditLogger $audit): void
    {
        $this->authorize('generate', Voucher::class);

        $user = auth()->user();
        $routerIds = $user->isSuperAdmin() ? Router::pluck('id')->all() : $user->routers()->pluck('routers.id')->all();

        $validated = $this->validate([
            'router_id' => ['required', 'integer', Rule::in($routerIds)],
            'plan_id' => ['required', 'exists:plans,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'prefix' => ['nullable', 'string', 'max:10', 'alpha_num'],
        ]);

        $router = Router::findOrFail($validated['router_id']);
        $plan = Plan::where('id', $validated['plan_id'])->where('router_id', $router->id)->where('is_active', true)->firstOrFail();

        $generated = 0;
        for ($i = 0; $i < $validated['quantity']; $i++) {
            $username = ($validated['prefix'] ? strtoupper($validated['prefix']).'-' : '').strtoupper(Str::random(6));
            $password = Str::random(8);

            $voucher = Voucher::create([
                'router_id' => $router->id,
                'plan_id' => $plan->id,
                'generated_by' => auth()->id(),
                'username' => $username,
                'password' => $password,
                'data_allowance_bytes' => $plan->data_allowance_bytes,
                'time_allowance_seconds' => $plan->time_allowance_seconds,
                'validity_seconds' => $plan->validity_seconds,
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
        
        $routers = $user->isSuperAdmin() ? Router::query()->orderBy('name')->get() : $user->routers()->orderBy('name')->get();
        $plans = collect();

        if ($this->router_id) {
            $plans = Plan::query()->where('is_active', true)->where('router_id', $this->router_id)->orderBy('name')->get();
        } else {
            $plans = Plan::query()->where('is_active', true)->orderBy('name')->get();
        }

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
            <select wire:model="router_id" class="mm-input">
                <option value="">Select router</option>
                @foreach ($routers as $r)
                    <option value="{{ $r->id }}">{{ $r->name }} ({{ $r->host }})</option>
                @endforeach
            </select>
            @error('router_id') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mm-label">Plan *</label>
            <select wire:model="plan_id" class="mm-input">
                <option value="">Select plan</option>
                @foreach ($plans as $p)
                    <option value="{{ $p->id }}">{{ $p->name }} - {{ $p->currency }} {{ $p->price }}</option>
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
