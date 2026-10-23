<?php

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Users')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void { $this->resetPage(); }

    public function render()
    {
        $this->authorize('viewAny', User::class);
        $query = User::query()->with('roles');
        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
            });
        }
        return $this->view([
            'users' => $query->latest()->paginate(15),
            'canCreate' => auth()->user()->can('create', User::class),
        ])->title('Users');
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Access Control</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Users</h1>
            <p class="mt-1 text-sm text-slate-500">Manage users, roles, and router access.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('users.create') }}" class="mm-btn-primary">Add user</a>
        @endif
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input max-w-sm" placeholder="Search users...">
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="mm-th">User</th>
                        <th class="mm-th">Email</th>
                        <th class="mm-th">Roles</th>
                        <th class="mm-th">Status</th>
                        <th class="mm-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($users as $user)
                        <tr>
                            <td class="mm-td font-semibold text-slate-900">{{ $user->name }}</td>
                            <td class="mm-td">{{ $user->email }}</td>
                            <td class="mm-td">{{ $user->roles->pluck('name')->implode(', ') }}</td>
                            <td class="mm-td text-xs">{{ $user->status->value }}</td>
                            <td class="mm-td text-right"><a href="{{ route('users.show', $user) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $users->links() }}</div>
        @endif
    </div>
</div>
