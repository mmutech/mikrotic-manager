<?php

use App\Models\Customer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Customers')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void { $this->resetPage(); }

    public function render()
    {
        $this->authorize('viewAny', Customer::class);

        $query = Customer::query()->withCount(['purchases', 'vouchers']);
        $query = $query->search($this->search);

        return $this->view([
            'customers' => $query->latest()->paginate(15),
            'canCreate' => auth()->user()->can('create', Customer::class),
        ])->title('Customers');
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Sales</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Customers</h1>
            <p class="mt-1 text-sm text-slate-500">Manage customer accounts and purchase history.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('customers.create') }}" class="mm-btn-primary">Add customer</a>
        @endif
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input max-w-sm" placeholder="Search customers...">
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="mm-th">Customer</th>
                        <th class="mm-th">Contact</th>
                        <th class="mm-th">Purchases</th>
                        <th class="mm-th">Vouchers</th>
                        <th class="mm-th">Status</th>
                        <th class="mm-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($customers as $customer)
                        <tr>
                            <td class="mm-td">
                                <div class="font-semibold text-slate-900">{{ $customer->name }}</div>
                                <div class="text-xs text-slate-500">{{ $customer->code }}</div>
                            </td>
                            <td class="mm-td">
                                <div class="text-sm text-slate-900">{{ $customer->email }}</div>
                                <div class="text-xs text-slate-500">{{ $customer->phone }}</div>
                            </td>
                            <td class="mm-td">{{ $customer->purchases_count }}</td>
                            <td class="mm-td">{{ $customer->vouchers_count }}</td>
                            <td class="mm-td">
                                <span class="text-xs">{{ $customer->status }}</span>
                            </td>
                            <td class="mm-td text-right">
                                <a href="{{ route('customers.show', $customer) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($customers->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $customers->links() }}</div>
        @endif
    </div>
</div>
