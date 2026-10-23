<?php

use App\Models\Transaction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Transaction details')] class extends Component
{
    public Transaction $transaction;

    public function mount(Transaction $transaction): void
    {
        $this->authorize('view', $transaction);
        $this->transaction = $transaction->load(['customer', 'plan', 'purchase', 'voucher', 'router']);
    }

    public function render()
    {
        $user = auth()->user();
        return $this->view([
            'canVerify' => $user->can('verify', $this->transaction),
            'canRefund' => $user->can('refund', $this->transaction),
        ])->title('Transaction '.$this->transaction->reference);
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('transactions.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to transactions</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900 font-mono">{{ $transaction->reference }}</h1>
        <p class="text-sm text-slate-500">Provider: {{ $transaction->provider }}</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="mm-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Transaction Details</h2>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Amount</dt>
                    <dd class="text-sm font-semibold text-slate-900">{{ $transaction->currency }} {{ number_format($transaction->amount, 2) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Status</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->status->value }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Channel</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->channel ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Provider Reference</dt>
                    <dd class="text-sm text-slate-900 font-mono text-xs">{{ $transaction->provider_reference ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Paid At</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->paid_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Verified At</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->verified_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="mm-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Related</h2>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Customer</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->customer->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Plan</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->plan->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Router</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->router->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Purchase</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->purchase ? 'Yes' : '—' }}</dd>
                </div>
            </dl>
            @if ($transaction->failure_reason)
                <div class="mt-4 pt-4 border-t border-slate-200">
                    <dt class="text-xs text-red-500">Failure Reason</dt>
                    <dd class="text-sm text-red-700 mt-1">{{ $transaction->failure_reason }}</dd>
                </div>
            @endif
        </div>
    </div>

    @if ($canVerify || $canRefund)
        <div class="mm-card p-4 flex gap-3">
            @if ($canVerify && $transaction->status->value === 'pending')
                <button class="mm-btn-primary">Verify Transaction</button>
            @endif
            @if ($canRefund && $transaction->status->value === 'success')
                <button class="mm-btn-danger">Refund Transaction</button>
            @endif
        </div>
    @endif
</div>
