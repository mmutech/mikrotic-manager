<?php

use App\Models\Plan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Plan details')] class extends Component
{
    public Plan $plan;

    public function mount(Plan $plan): void
    {
        $this->authorize('view', $plan);
        $this->plan = $plan;
    }

    public function render()
    {
        return $this->view([
            'canUpdate' => auth()->user()->can('update', $this->plan),
        ])->title($this->plan->name);
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('plans.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to plans</a>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ $plan->name }}</h1>
            <p class="text-sm text-slate-500">{{ $plan->code }}</p>
        </div>
        @if ($canUpdate)
            <a href="{{ route('plans.edit', $plan) }}" class="mm-btn-secondary">Edit plan</a>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="mm-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Plan Details</h2>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Router</dt>
                    <dd class="text-sm text-slate-900">{{ $plan->router->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Price</dt>
                    <dd class="text-sm font-semibold text-slate-900">{{ $plan->currency }} {{ number_format($plan->price, 2) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Data Quota</dt>
                    <dd class="text-sm text-slate-900">{{ $plan->quotaLabel() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Validity</dt>
                    <dd class="text-sm text-slate-900">{{ $plan->validityLabel() ?? 'No expiry' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Status</dt>
                    <dd class="text-sm text-slate-900">{{ $plan->is_active ? 'Active' : 'Inactive' }}</dd>
                </div>
            </dl>
            @if ($plan->description)
                <div class="mt-4 pt-4 border-t border-slate-200">
                    <p class="text-sm text-slate-600">{{ $plan->description }}</p>
                </div>
            @endif
        </div>

        <div class="mm-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Statistics</h2>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Vouchers</dt>
                    <dd class="text-sm font-semibold text-slate-900">{{ $plan->vouchers()->count() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Purchases</dt>
                    <dd class="text-sm font-semibold text-slate-900">{{ $plan->purchases()->count() }}</dd>
                </div>
            </dl>
        </div>
    </div>
</div>
