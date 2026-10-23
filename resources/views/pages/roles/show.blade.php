<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts::app')] #[Title('Role details')] class extends Component
{
    public Role $role;

    public function mount(Role $role): void
    {
        $this->authorize('assignRoles', App\Models\User::class);
        $this->role = $role->load('permissions');
    }

    public function render()
    {
        return $this->view()->title($this->role->name);
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('roles.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to roles</a>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ $role->name }}</h1>
        </div>
        <a href="{{ route('roles.permissions', $role) }}" class="mm-btn-secondary">Manage Permissions</a>
    </div>
    <div class="mm-card p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Permissions ({{ $role->permissions->count() }})</h2>
        <div class="flex flex-wrap gap-2">
            @foreach ($role->permissions as $perm)
                <span class="mm-badge bg-slate-100 text-slate-700 ring-slate-500/20">{{ $perm->name }}</span>
            @endforeach
        </div>
    </div>
</div>
