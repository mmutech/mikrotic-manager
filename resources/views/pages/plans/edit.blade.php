<?php

use App\Models\Plan;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Edit plan')] class extends Component
{
    public Plan $plan;

    public string $name = '';
    public string $description = '';
    public string $price = '';
    public string $currency = '';
    public string $data_allowance_mb = '';
    public bool $is_unlimited = false;
    public string $validity_days = '';
    public bool $is_active = true;

    public function mount(Plan $plan): void
    {
        $this->authorize('update', $plan);
        $this->plan = $plan;
        $this->name = $plan->name;
        $this->description = (string) $plan->description;
        $this->price = (string) $plan->price;
        $this->currency = $plan->currency;
        $this->is_unlimited = (bool) $plan->is_unlimited;
        $this->is_active = (bool) $plan->is_active;
        $this->data_allowance_mb = $plan->data_allowance_bytes ? (string) ((int) $plan->data_allowance_bytes / 1024 / 1024) : '';
        $this->validity_days = $plan->validity_seconds ? (string) ((int) $plan->validity_seconds / 86400) : '';
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('update', $this->plan);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'data_allowance_mb' => ['nullable', 'integer', 'min:0', 'required_unless:is_unlimited,true'],
            'is_unlimited' => ['boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ]);

        $before = $this->plan->toArray();

        $this->plan->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'price' => $validated['price'],
            'currency' => strtoupper($validated['currency']),
            'is_unlimited' => $validated['is_unlimited'],
            'data_allowance_bytes' => $validated['is_unlimited'] ? null : ((int) ($validated['data_allowance_mb'] ?? 0) * 1024 * 1024),
            'validity_seconds' => ! empty($validated['validity_days']) ? (int) $validated['validity_days'] * 86400 : null,
            'is_active' => $validated['is_active'],
        ]);

        $audit->record('plan.updated', $this->plan, before: $before, after: $this->plan->fresh()->toArray(), actor: auth()->user());

        session()->flash('status', 'Plan updated successfully.');
        $this->redirectRoute('plans.show', ['plan' => $this->plan->public_id], navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Edit plan');
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
            <a href="{{ route('plans.show', $plan) }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Save changes</button>
        </div>
    </form>
</div>
