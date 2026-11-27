<?php

use App\Enums\SubscriptionType;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\SubscriptionPricingService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Subscription')] class extends Component
{
    public string $type = SubscriptionType::Monthly->value;

    public function request(SubscriptionPricingService $pricing): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin() || ! $user->requiresSubscription()) {
            $this->dispatch('app-alert', title: 'Subscription not required', message: 'No subscription is required for your account.', type: 'success');

            return;
        }

        $pending = $user->subscriptions()->where('status', 'pending')->exists();

        if ($pending) {
            $this->dispatch('app-alert', title: 'Renewal already pending', message: 'You already have a pending renewal request.', type: 'warning');

            return;
        }

        $validated = $this->validate([
            'type' => ['required', Rule::enum(SubscriptionType::class)],
        ]);

        $type = SubscriptionType::from($validated['type']);

        Subscription::create([
            'user_id' => $user->id,
            'plan' => $type->value,
            'type' => $type,
            'status' => 'pending',
            'amount' => $pricing->priceFor($type),
            'currency' => $pricing->currency(),
            'metadata' => ['requested_at' => now()->toIso8601String()],
        ]);

        app(AuditLogger::class)->record('subscription.requested', $user, actor: $user);

        $this->dispatch('app-alert', title: 'Renewal request submitted', message: 'An administrator will activate your subscription request.', type: 'success');
    }

    public function render(SubscriptionPricingService $pricing)
    {
        $user = auth()->user();

        return $this->view([
            'subscription' => $user->activeSubscription(),
            'history' => $user->subscriptions()->latest()->limit(10)->get(),
            'requiresSubscription' => $user->requiresSubscription(),
            'pending' => $user->subscriptions()->where('status', 'pending')->exists(),
            'plans' => $plans = $pricing->options(),
            'currency' => $plans[0]['currency'] ?? 'NGN',
        ])->title('Subscription');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Account</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Subscription</h1>
        <p class="mt-1 text-sm text-slate-500">Network administrators need an active subscription to operate the platform.</p>
    </div>

    @if (! $requiresSubscription)
        <div class="mm-card p-6">
            <p class="text-sm text-slate-600">Your role does not require a subscription.</p>
        </div>
    @else
        <div class="mm-card p-6">
            @if ($subscription)
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">Active subscription</p>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ $subscription->typeLabel() }} · Expires {{ $subscription->expires_at->format('Y-m-d') }}
                            ({{ $subscription->daysRemaining() }} days remaining)
                        </p>
                    </div>
                    <span class="mm-badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">Active</span>
                </div>
            @else
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">No active subscription</p>
                        <p class="mt-1 text-sm text-slate-600">Request a renewal to regain access to the platform.</p>
                    </div>
                    <div class="w-full max-w-xs space-y-2">
                        <label class="mm-label" for="subscription-type">Plan</label>
                        <select id="subscription-type" wire:model="type" class="mm-input" @disabled($pending)>
                            @foreach ($plans as $option)
                                <option value="{{ $option['value'] }}">
                                    {{ $option['label'] }} · {{ $option['currency'] }} {{ number_format($option['price'], 2) }} / {{ $option['days'] }} days
                                </option>
                            @endforeach
                        </select>
                        @error('type') <p class="mm-error">{{ $message }}</p> @enderror
                        <button wire:click="request" class="mm-btn-primary" @disabled($pending)>
                            {{ $pending ? 'Request pending' : 'Request renewal' }}
                        </button>
                    </div>
                </div>
            @endif
        </div>

        <div class="mm-card overflow-hidden">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-slate-900">History</h2>
            </div>
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="mm-th">Plan</th>
                        <th class="mm-th">Duration</th>
                        <th class="mm-th">Price</th>
                        <th class="mm-th">Status</th>
                        <th class="mm-th">Starts</th>
                        <th class="mm-th">Expires</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($history as $row)
                        <tr>
                            <td class="mm-td">{{ $row->typeLabel() }}</td>
                            <td class="mm-td">{{ $row->durationDays() }} days</td>
                            <td class="mm-td">{{ $row->currency }} {{ number_format($row->amount, 2) }}</td>
                            <td class="mm-td text-xs">{{ $row->status }}</td>
                            <td class="mm-td text-xs">{{ $row->starts_at?->format('Y-m-d') ?? '—' }}</td>
                            <td class="mm-td text-xs">{{ $row->expires_at?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="mm-td text-center text-slate-500">No subscriptions yet</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
