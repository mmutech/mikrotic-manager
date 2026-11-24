<?php

use App\Models\User;
use App\Services\SubscriptionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Manage subscription')] class extends Component
{
    public User $user;
    public int $days = 30;
    public string $amount = '0';
    public string $plan = 'basic';
    public bool $showDeactivateConfirmation = false;

    public function mount(User $user): void
    {
        $this->authorize('viewAny', User::class);
        $this->user = $user;
    }

    public function activate(SubscriptionService $service): void
    {
        $this->authorize('viewAny', User::class);
        $validated = $this->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'amount' => ['required', 'numeric', 'min:0'],
            'plan' => ['required', 'string', 'max:40'],
        ]);

        $service->activate($this->user, (int) $validated['days'], $validated['plan'], (float) $validated['amount']);

        session()->flash('status', 'Subscription activated until '.$this->user->fresh()->activeSubscription()?->expires_at?->format('Y-m-d').'.');
        $this->redirectRoute('subscriptions.manage', navigate: true);
    }

    public function deactivate(SubscriptionService $service): void
    {
        $this->authorize('viewAny', User::class);
        $service->deactivate($this->user);
        session()->flash('status', 'Subscription deactivated.');
        $this->redirectRoute('subscriptions.manage', navigate: true);
    }

    public function openDeactivateConfirmation(): void
    {
        $this->authorize('viewAny', User::class);
        $this->showDeactivateConfirmation = true;
    }

    public function closeDeactivateConfirmation(): void
    {
        $this->showDeactivateConfirmation = false;
    }

    public function render()
    {
        return $this->view([
            'subscription' => $this->user->activeSubscription(),
            'history' => $this->user->subscriptions()->latest()->limit(20)->get(),
        ])->title('Manage subscription');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('subscriptions.manage') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to subscriptions</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ $user->name }}</h1>
        <p class="text-sm text-slate-500">{{ $user->email }}</p>
    </div>

    @if ($subscription)
        <div class="mm-card p-6">
            <p class="text-sm font-semibold text-slate-900">Active until {{ $subscription->expires_at->format('Y-m-d') }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ $subscription->daysRemaining() }} days remaining · {{ $subscription->plan }}</p>
            <button wire:click="openDeactivateConfirmation" class="mm-btn-danger mt-4">Deactivate</button>
        </div>
    @else
        <div class="mm-card p-6">
            <p class="text-sm text-slate-600">No active subscription.</p>
        </div>
    @endif

    @if ($showDeactivateConfirmation)
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/60 p-0 backdrop-blur-sm sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="deactivate-subscription-title">
            <button type="button" class="absolute inset-0 h-full w-full cursor-default" wire:click="closeDeactivateConfirmation" aria-label="Cancel deactivation"></button>
            <div class="relative z-10 w-full rounded-t-3xl bg-white p-5 shadow-2xl sm:max-w-lg sm:rounded-3xl sm:p-6">
                <h2 id="deactivate-subscription-title" class="text-lg font-semibold text-slate-900">Deactivate subscription?</h2>
                <p class="mt-3 text-sm leading-6 text-slate-600">This subscription will stop being active immediately.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="closeDeactivateConfirmation" class="mm-btn-secondary">Cancel</button>
                    <button type="button" wire:click="deactivate" wire:loading.attr="disabled" class="mm-btn-danger">Deactivate</button>
                </div>
            </div>
        </div>
    @endif

    <form wire:submit="activate" class="mm-card space-y-5 p-6">
        <h2 class="text-lg font-semibold text-slate-900">Activate / Extend</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mm-label">Plan</label>
                <input type="text" wire:model="plan" class="mm-input">
                @error('plan') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Days</label>
                <input type="number" wire:model="days" class="mm-input" min="1" max="365">
                @error('days') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Amount (NGN)</label>
                <input type="number" step="0.01" wire:model="amount" class="mm-input">
                @error('amount') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex justify-end gap-3">
            <a href="{{ route('subscriptions.manage') }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Activate</button>
        </div>
    </form>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-semibold text-slate-900">History</h2></div>
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="mm-th">Plan</th>
                    <th class="mm-th">Status</th>
                    <th class="mm-th">Starts</th>
                    <th class="mm-th">Expires</th>
                    <th class="mm-th">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse ($history as $row)
                    <tr>
                        <td class="mm-td">{{ $row->plan }}</td>
                        <td class="mm-td text-xs">{{ $row->status }}</td>
                        <td class="mm-td text-xs">{{ $row->starts_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="mm-td text-xs">{{ $row->expires_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="mm-td">{{ $row->currency }} {{ number_format($row->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mm-td text-center text-slate-500">No subscriptions yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
