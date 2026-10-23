<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts::app')] #[Title('Roles')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('assignRoles', App\Models\User::class);
    }

    public function render()
    {
        return $this->view([
            'roles' => Role::withCount(['permissions', 'users'])->get(),
        ])->title('Roles');
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Access Control</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Roles & Permissions</h1>
            <p class="mt-1 text-sm text-slate-500">Manage roles and their permissions.</p>
        </div>
        <a href="{{ route('roles.create') }}" class="mm-btn-primary">Create role</a>
    </div>

    <div class="mm-card overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="mm-th">Role</th>
                    <th class="mm-th">Permissions</th>
                    <th class="mm-th">Users</th>
                    <th class="mm-th text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @foreach ($roles as $role)
                    <tr>
                        <td class="mm-td font-semibold text-slate-900">{{ $role->name }}</td>
                        <td class="mm-td">{{ $role->permissions_count }}</td>
                        <td class="mm-td">{{ $role->users_count }}</td>
                        <td class="mm-td text-right"><a href="{{ route('roles.show', $role) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
