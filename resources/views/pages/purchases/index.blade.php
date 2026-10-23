<?php

use App\Models\Purchase;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Purchases')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    public function render()
    {
        $query = Purchase::query()->with(['customer', 'plan', 'router']);
        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->whereHas('customer', function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
            });
        }
        if ($this->status !== '') {
            $query->where('status', $this->status);
        }
        return $this->view([
            'purchases' => $query->latest()->paginate(20),
        ])->title('Purchases');
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Sales</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Purchases</h1>
        <p class="mt-1 text-sm text-slate-500">Track purchases and fulfillment.</p>
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4 grid gap-3 sm:grid-cols-2">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input" placeholder="Search customer...">
            <select wire:model.live="status" class="mm-input">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="paid">Paid</option>
                <option value="fulfilled">Fulfilled</option>
                <option value="failed">Failed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="mm-th">Customer</th>
                        <th class="mm-th">Plan</th>
                        <th class="mm-th">Router</th>
                        <th class="mm-th">Qty</th>
                        <th class="mm-th">Total</th>
                        <th class="mm-th">Status</th>
                        <th class="mm-th">Date</th>
                        <th class="mm-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($purchases as $purchase)
                        <tr>
                            <td class="mm-td">{{ $purchase->customer->name ?? '—' }}</td>
                            <td class="mm-td">{{ $purchase->plan->name ?? '—' }}</td>
                            <td class="mm-td">{{ $purchase->router->name ?? '—' }}</td>
                            <td class="mm-td">{{ $purchase->quantity }}</td>
                            <td class="mm-td">{{ $purchase->currency }} {{ number_format($purchase->total_amount, 2) }}</td>
                            <td class="mm-td text-xs">{{ $purchase->status->value }}</td>
                            <td class="mm-td text-xs text-slate-500">{{ $purchase->created_at->format('Y-m-d H:i') }}</td>
                            <td class="mm-td text-right"><a href="{{ route('purchases.show', $purchase) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($purchases->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $purchases->links() }}</div>
        @endif
    </div>
</div>
