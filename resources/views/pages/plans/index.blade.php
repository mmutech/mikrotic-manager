<?php

use App\Models\Plan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Plans')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $this->authorize('viewAny', Plan::class);

        $query = Plan::query()->with(['creator', 'router']);

        $user = auth()->user();
        if (! $user->isSuperAdmin()) {
            $query->whereIn('router_id', $user->routers()->pluck('routers.id'));
        }

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('code', 'like', $term);
            });
        }

        return $this->view([
            'plans' => $query->latest()->paginate(15),
            'canCreate' => auth()->user()->can('create', Plan::class),
        ])->title('Plans');
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Packages</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Data Plans</h1>
            <p class="mt-1 text-sm text-slate-500">Manage hotspot data bundle plans and pricing.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('plans.create') }}" class="mm-btn-primary">Create plan</a>
        @endif
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input max-w-sm" placeholder="Search plans...">
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="mm-th">Plan</th>
                        <th class="mm-th">Router</th>
                        <th class="mm-th">Price</th>
                        <th class="mm-th">Quota</th>
                        <th class="mm-th">Validity</th>
                        <th class="mm-th">Status</th>
                        <th class="mm-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($plans as $plan)
                        <tr>
                            <td class="mm-td">
                                <div class="font-semibold text-slate-900">{{ $plan->name }}</div>
                                <div class="text-xs text-slate-500">{{ $plan->code }}</div>
                            </td>
                            <td class="mm-td">{{ $plan->router->name ?? '—' }}</td>
                            <td class="mm-td">{{ $plan->currency }} {{ number_format($plan->price, 2) }}</td>
                            <td class="mm-td">{{ $plan->quotaLabel() }}</td>
                            <td class="mm-td">{{ $plan->validityLabel() ?? '—' }}</td>
                            <td class="mm-td">
                                <span class="mm-badge {{ $plan->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-slate-100 text-slate-600 ring-slate-500/20' }}">
                                    {{ $plan->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="mm-td text-right">
                                <a href="{{ route('plans.show', $plan) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($plans->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $plans->links() }}</div>
        @endif
    </div>
</div>
