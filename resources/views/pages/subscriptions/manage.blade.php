<?php

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Manage subscriptions')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void { $this->resetPage(); }

    public function render()
    {
        $query = User::query()->role('network-admin');
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
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Network Admin Subscriptions</h1>
        <p class="mt-1 text-sm text-slate-500">Activate or review subscriptions for network administrators.</p>
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input max-w-sm" placeholder="Search network admins...">
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
                                <span class="mm-badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">Active until {{ $active->expires_at->format('Y-m-d') }}</span>
                            @else
                                <span class="mm-badge bg-red-50 text-red-700 ring-red-600/20">Expired</span>
                            @endif
                        </td>
                        <td class="mm-td text-right">
                            <a href="{{ route('subscriptions.user', $user) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">Manage</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="mm-td text-center text-slate-500">No network administrators found</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($users->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $users->links() }}</div>
        @endif
    </div>
</div>
