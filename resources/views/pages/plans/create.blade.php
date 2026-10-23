<?php

use App\Models\Plan;
use App\Models\Router;
use App\Services\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Create plan')] class extends Component
{
    public string $name = '';
    public string $router_id = '';
    public string $description = '';
    public string $price = '0.00';
    public string $currency = 'NGN';
    public string $data_allowance_mb = '';
    public bool $is_unlimited = false;
    public string $validity_days = '';
    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('create', Plan::class);
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

        $user = auth()->user();
        $routerIds = $user->isSuperAdmin() ? Router::pluck('id')->all() : $user->routers()->pluck('routers.id')->all();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'router_id' => ['required', 'integer', Rule::in($routerIds)],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'data_allowance_mb' => ['nullable', 'integer', 'min:0', 'required_unless:is_unlimited,true'],
            'is_unlimited' => ['boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ]);

        $data = [
            'router_id' => $validated['router_id'],
            'name' => $validated['name'],
            'code' => $this->generateCode(),
            'description' => $validated['description'] ?: null,
            'price' => $validated['price'],
            'currency' => strtoupper($validated['currency']),
            'is_unlimited' => $validated['is_unlimited'],
            'data_allowance_bytes' => $validated['is_unlimited'] ? null : ((int) ($validated['data_allowance_mb'] ?? 0) * 1024 * 1024),
            'validity_seconds' => ! empty($validated['validity_days']) ? (int) $validated['validity_days'] * 86400 : null,
            'is_active' => $validated['is_active'],
            'created_by' => auth()->id(),
        ];

        $plan = Plan::create($data);

        $audit->record('plan.created', $plan, after: $plan->fresh()->toArray(), actor: auth()->user());

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
                <select wire:model="router_id" class="mm-input">
                    <option value="">Select router</option>
                    @foreach ($routers as $r)
                        <option value="{{ $r->id }}">{{ $r->name }} ({{ $r->host }})</option>
                    @endforeach
                </select>
                @error('router_id') <p class="mm-error">{{ $message }}</p> @enderror
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

        <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.live="is_unlimited" id="unlimited" class="rounded border-slate-300">
            <label for="unlimited" class="text-sm text-slate-700">Unlimited data</label>
        </div>

        @unless ($is_unlimited)
            <div>
                <label class="mm-label">Data allowance (MB) *</label>
                <input type="number" wire:model="data_allowance_mb" class="mm-input">
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
