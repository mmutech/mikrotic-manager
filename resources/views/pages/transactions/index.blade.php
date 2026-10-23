<?php

use App\Models\Transaction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Transactions')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    public function render()
    {
        $this->authorize('viewAny', Transaction::class);

        $query = Transaction::query()->with(['customer', 'plan']);

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where('reference', 'like', $term)->orWhere('provider_reference', 'like', $term);
        }
        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return $this->view([
            'transactions' => $query->latest()->paginate(20),
        ])->title('Transactions');
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Payments</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Transactions</h1>
        <p class="mt-1 text-sm text-slate-500">Monitor payments and transaction status.</p>
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4 grid gap-3 sm:grid-cols-2">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input" placeholder="Search reference...">
            <select wire:model.live="status" class="mm-input">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="success">Success</option>
                <option value="failed">Failed</option>
                <option value="abandoned">Abandoned</option>
                <option value="reversed">Reversed</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="mm-th">Reference</th>
                        <th class="mm-th">Customer</th>
                        <th class="mm-th">Plan</th>
                        <th class="mm-th">Amount</th>
                        <th class="mm-th">Status</th>
                        <th class="mm-th">Date</th>
                        <th class="mm-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($transactions as $transaction)
                        <tr>
                            <td class="mm-td font-mono text-xs">{{ $transaction->reference }}</td>
                            <td class="mm-td">{{ $transaction->customer->name ?? '—' }}</td>
                            <td class="mm-td">{{ $transaction->plan->name ?? '—' }}</td>
                            <td class="mm-td">{{ $transaction->currency }} {{ number_format($transaction->amount, 2) }}</td>
                            <td class="mm-td">
                                <span class="text-xs">{{ $transaction->status->value }}</span>
                            </td>
                            <td class="mm-td text-xs text-slate-500">{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                            <td class="mm-td text-right">
                                <a href="{{ route('transactions.show', $transaction) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($transactions->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $transactions->links() }}</div>
        @endif
    </div>
</div>
