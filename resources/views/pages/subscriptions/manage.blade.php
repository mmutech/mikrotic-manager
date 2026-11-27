<?php

use App\Enums\SubscriptionType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SubscriptionPricingService;
use App\Support\Roles;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Manage subscriptions')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $monthlyPrice = '0';

    public string $yearlyPrice = '0';

    public string $currency = 'NGN';

    public function mount(SubscriptionPricingService $pricing): void
    {
        $this->authorize('viewAny', User::class);
        $this->currency = $pricing->currency();
        $this->monthlyPrice = (string) $pricing->priceFor(SubscriptionType::Monthly);
        $this->yearlyPrice = (string) $pricing->priceFor(SubscriptionType::Yearly);
    }

    public function savePrices(SubscriptionPricingService $pricing, AuditLogger $audit): void
    {
        $this->authorize('viewAny', User::class);
        $validated = $this->validate([
            'monthlyPrice' => ['required', 'numeric', 'min:0'],
            'yearlyPrice' => ['required', 'numeric', 'min:0'],
        ]);

        $prices = [
            SubscriptionType::Monthly->value => (float) $validated['monthlyPrice'],
            SubscriptionType::Yearly->value => (float) $validated['yearlyPrice'],
        ];

        $pricing->updatePrices($prices, auth()->user());
        $audit->record('subscription.prices_updated', actor: auth()->user(), metadata: $prices);

        $this->monthlyPrice = (string) $prices['monthly'];
        $this->yearlyPrice = (string) $prices['yearly'];
        session()->flash('status', 'Subscription type prices updated.');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = User::query()->role(Roles::SUBSCRIBERS);
        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return $this->view([
            'users' => $query->orderBy('name')->paginate(15),
        ])->title('Manage subscriptions');
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Billing</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Subscriber Subscriptions</h1>
        <p class="mt-1 text-sm text-slate-500">Set plan prices and review subscriptions for network administrators and sales agents.</p>
    </div>

    <form wire:submit="savePrices" class="mm-card space-y-4 p-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Subscription type prices</h2>
            <p class="mt-1 text-sm text-slate-500">These prices are applied to new requests and activations.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mm-label">Monthly price ({{ $currency }})</label>
                <input type="number" step="0.01" min="0" wire:model="monthlyPrice" class="mm-input">
                @error('monthlyPrice') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Yearly price ({{ $currency }})</label>
                <input type="number" step="0.01" min="0" wire:model="yearlyPrice" class="mm-input">
                @error('yearlyPrice') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="mm-btn-primary" wire:loading.attr="disabled">Save prices</button>
        </div>
    </form>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input max-w-sm" placeholder="Search subscribers...">
        </div>
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="mm-th">User</th>
                    <th class="mm-th">Email</th>
                    <th class="mm-th">Subscription</th>
                    <th class="mm-th text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse ($users as $user)
                    @php($active = $user->activeSubscription())
                    <tr>
                        <td class="mm-td font-semibold text-slate-900">{{ $user->name }}</td>
                        <td class="mm-td">{{ $user->email }}</td>
                        <td class="mm-td text-xs">
                            @if ($active)
                                <span class="mm-badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">{{ $active->typeLabel() }} · Active until {{ $active->expires_at->format('Y-m-d') }}</span>
                            @else
                                <span class="mm-badge bg-red-50 text-red-700 ring-red-600/20">Expired</span>
                            @endif
                        </td>
                        <td class="mm-td text-right">
                            <a href="{{ route('subscriptions.user', $user) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">Manage</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="mm-td text-center text-slate-500">No subscribers found</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($users->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $users->links() }}</div>
        @endif
    </div>
</div>
