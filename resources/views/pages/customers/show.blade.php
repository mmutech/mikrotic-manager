<?php

use App\Models\Customer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Customer details')] class extends Component
{
    public Customer $customer;

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer->load(['purchases', 'vouchers', 'transactions']);
    }

    public function render()
    {
        return $this->view([
            'canUpdate' => auth()->user()->can('update', $this->customer),
        ])->title($this->customer->name);
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('customers.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to customers</a>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ $customer->name }}</h1>
            <p class="text-sm text-slate-500">{{ $customer->code }}</p>
        </div>
        @if ($canUpdate)
            <a href="{{ route('customers.edit', $customer) }}" class="mm-btn-secondary">Edit customer</a>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="mm-card p-6 lg:col-span-1">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Contact Info</h2>
            <dl class="space-y-3">
                <div>
                    <dt class="text-xs text-slate-500">Email</dt>
                    <dd class="text-sm text-slate-900">{{ $customer->email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Phone</dt>
                    <dd class="text-sm text-slate-900">{{ $customer->phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Status</dt>
                    <dd class="text-sm text-slate-900">{{ $customer->status }}</dd>
                </div>
            </dl>
        </div>

        <div class="mm-card overflow-hidden lg:col-span-2">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-slate-900">Purchases</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="mm-th">Plan</th>
                            <th class="mm-th">Quantity</th>
                            <th class="mm-th">Amount</th>
                            <th class="mm-th">Status</th>
                            <th class="mm-th">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($customer->purchases as $purchase)
                            <tr>
                                <td class="mm-td">{{ $purchase->plan->name ?? '—' }}</td>
                                <td class="mm-td">{{ $purchase->quantity }}</td>
                                <td class="mm-td">{{ $purchase->currency }} {{ number_format($purchase->total_amount, 2) }}</td>
                                <td class="mm-td text-xs">{{ $purchase->status->value }}</td>
                                <td class="mm-td text-xs text-slate-500">{{ $purchase->created_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="mm-td text-center text-slate-500">No purchases yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
