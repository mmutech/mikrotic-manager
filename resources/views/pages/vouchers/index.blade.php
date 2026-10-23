<?php

use App\Models\Router;
use App\Models\Voucher;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Vouchers')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $router = '';
    public string $lifecycle = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedRouter(): void { $this->resetPage(); }
    public function updatedLifecycle(): void { $this->resetPage(); }

    public function render()
    {
        $this->authorize('viewAny', Voucher::class);

        $query = Voucher::query()->with(['plan', 'router', 'customer']);

        $user = auth()->user();
        if (! $user->isSuperAdmin()) {
            $query->whereIn('router_id', $user->routers()->pluck('routers.id'));
        }

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where('username', 'like', $term);
        }
        if ($this->router !== '') {
            $query->where('router_id', $this->router);
        }
        if ($this->lifecycle !== '') {
            $query->where('lifecycle_status', $this->lifecycle);
        }

        return $this->view([
            'vouchers' => $query->latest()->paginate(20),
            'routers' => Router::query()->orderBy('name')->get(),
            'canGenerate' => auth()->user()->can('generate', Voucher::class),
        ])->title('Vouchers');
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Hotspot Access</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Vouchers</h1>
            <p class="mt-1 text-sm text-slate-500">Generate and manage hotspot vouchers separately from router provisioning.</p>
        </div>
        @if ($canGenerate)
            <a href="{{ route('vouchers.generate') }}" class="mm-btn-primary">Generate vouchers</a>
        @endif
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4 grid gap-3 sm:grid-cols-3">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input" placeholder="Search username...">
            <select wire:model.live="router" class="mm-input">
                <option value="">All routers</option>
                @foreach ($routers as $r)
                    <option value="{{ $r->id }}">{{ $r->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="lifecycle" class="mm-input">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="active">Active</option>
                <option value="used">Used</option>
                <option value="expired">Expired</option>
                <option value="revoked">Revoked</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="mm-th">Username</th>
                        <th class="mm-th">Plan</th>
                        <th class="mm-th">Router</th>
                        <th class="mm-th">Lifecycle</th>
                        <th class="mm-th">Provisioning</th>
                        <th class="mm-th">Created</th>
                        <th class="mm-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($vouchers as $voucher)
                        <tr>
                            <td class="mm-td font-mono text-sm">{{ $voucher->username }}</td>
                            <td class="mm-td">{{ $voucher->plan->name ?? '—' }}</td>
                            <td class="mm-td">{{ $voucher->router->name ?? '—' }}</td>
                            <td class="mm-td">
                                <span class="text-xs">{{ $voucher->lifecycle_status->value }}</span>
                            </td>
                            <td class="mm-td">
                                <span class="text-xs">{{ $voucher->provisioning_status->value }}</span>
                            </td>
                            <td class="mm-td text-xs text-slate-500">{{ $voucher->created_at->format('Y-m-d H:i') }}</td>
                            <td class="mm-td text-right">
                                <a href="{{ route('vouchers.show', $voucher) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($vouchers->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $vouchers->links() }}</div>
        @endif
    </div>
</div>
