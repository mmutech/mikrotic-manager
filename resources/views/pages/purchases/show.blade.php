<?php

use App\Models\Purchase;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Purchase details')] class extends Component
{
    public Purchase $purchase;

    public function mount(Purchase $purchase): void
    {
        $this->purchase = $purchase->load(['customer', 'plan', 'router', 'transaction', 'vouchers']);
    }

    public function render()
    {
        return $this->view()->title('Purchase');
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('purchases.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to purchases</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Purchase Details</h1>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="mm-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Purchase Info</h2>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Customer</dt>
                    <dd class="text-sm text-slate-900">{{ $purchase->customer->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Plan</dt>
                    <dd class="text-sm text-slate-900">{{ $purchase->plan->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Router</dt>
                    <dd class="text-sm text-slate-900">{{ $purchase->router->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Quantity</dt>
                    <dd class="text-sm text-slate-900">{{ $purchase->quantity }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Unit Price</dt>
                    <dd class="text-sm text-slate-900">{{ $purchase->currency }} {{ number_format($purchase->unit_price, 2) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Total</dt>
                    <dd class="text-sm font-semibold text-slate-900">{{ $purchase->currency }} {{ number_format($purchase->total_amount, 2) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Status</dt>
                    <dd class="text-sm text-slate-900">{{ $purchase->status->value }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Date</dt>
                    <dd class="text-sm text-slate-900">{{ $purchase->created_at->format('Y-m-d H:i:s') }}</dd>
                </div>
            </dl>
        </div>

        <div class="mm-card overflow-hidden">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-slate-900">Generated Vouchers</h2>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($purchase->vouchers as $voucher)
                    <div class="px-6 py-3 flex items-center justify-between">
                        <span class="font-mono text-sm">{{ $voucher->username }}</span>
                        <a href="{{ route('vouchers.show', $voucher) }}" class="text-sm text-brand-600 hover:text-brand-700">View</a>
                    </div>
                @empty
                    <div class="px-6 py-4 text-center text-sm text-slate-500">No vouchers generated yet</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
